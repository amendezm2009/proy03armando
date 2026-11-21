<?php

namespace App\Actions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Instala un paquete ya subido (inbox/v{versión}/) paso a paso, con regreso al estado anterior si algo falla.
 * Cada llamada a step() hace un poco de trabajo (config('release.step_seconds')) y devuelve el avance; la página
 * las repite hasta terminar, así ninguna petición pasa el límite de ejecución del hosting. Fases, en orden:
 *
 *   maintenance  pone la aplicación en mantenimiento (solo /admin/actualizaciones* sigue disponible)
 *   backup       respaldo de la base de datos (BackupDatabase)
 *   assemble     une las partes en un ZIP y lee su inventario (RELEASE.json)
 *   extract      extrae los archivos a work/staging/ comprobando el SHA-256 de cada uno (por tandas; no toca la app)
 *   swap         reemplaza las carpetas de la aplicación: lo viejo se MUEVE a work/rollback/, lo nuevo ocupa su lugar
 *   migrate      php artisan migrate --force
 *   finish       comprueba la versión, limpia cachés, anota installed.json y quita el mantenimiento
 *
 * El reemplazo es por "unidades" (ver unitOf): carpetas completas como app/, vendor/ o public/build/ y archivos de la
 * raíz. Mover una carpeta es una sola operación del sistema de archivos, así que el cambio de código es casi
 * instantáneo y no deja la aplicación a medias aunque el servidor sea lento. Nunca se toca .env, storage/,
 * bootstrap/cache/ ni nada que el paquete no traiga (lo protegido que sí viaja en el paquete se ignora); lo que
 * sobra de la versión anterior (según installed.json) también se aparta.
 *
 * Si una fase falla: restaura la base de datos (si ya se había migrado), devuelve las carpetas movidas, quita el
 * mantenimiento y deja la fase en "rolled_back" con el motivo. Si ni así se puede, queda "rollback_failed" y en
 * work/rollback/ están las carpetas originales para devolverlas a mano.
 *
 * USO: $estado = app(RunUpdate::class)->start('1.2.0', $usuario->id);
 *      do { $estado = app(RunUpdate::class)->step(); } while (! $estado['finished']);
 */
class RunUpdate
{
    public const TERMINAL = ['done', 'rolled_back', 'rollback_failed'];

    private const LABELS = [
        'maintenance' => 'Poniendo la aplicación en mantenimiento',
        'backup' => 'Respaldando la base de datos',
        'assemble' => 'Uniendo las partes del paquete',
        'extract' => 'Extrayendo y comprobando los archivos',
        'swap' => 'Reemplazando el código',
        'migrate' => 'Aplicando las migraciones',
        'finish' => 'Comprobando la instalación',
        'done' => 'Instalación terminada',
        'rolled_back' => 'La instalación falló y se revirtió',
        'rollback_failed' => 'La instalación falló y NO se pudo revertir sola',
    ];

    public function __construct(private readonly UpdateStorage $storage, private readonly UpdateHooks $hooks) {}

    /**
     * La unidad de reemplazo a la que pertenece un archivo del paquete, o null si ese archivo está protegido
     * (.env, storage/, .git/, bootstrap/cache/) y nunca se reemplaza.
     */
    public static function unitOf(string $path): ?string
    {
        $segments = explode('/', $path);
        $first = $segments[0];

        if ($first === '' || $first === 'storage' || $first === '.git' || $first === '.env' || (str_starts_with($first, '.env.') && $first !== '.env.example')) {
            return null;
        }

        if (in_array($first, ['public', 'bootstrap'], true)) {
            $second = $segments[1] ?? null;

            return $second === null || ($first === 'bootstrap' && $second === 'cache') ? null : "{$first}/{$second}";
        }

        return $first;
    }

    /**
     * @return array<string, mixed>|null El estado de la última instalación, o null si nunca hubo una.
     */
    public function state(): ?array
    {
        $file = $this->stateFile();
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * El avance de la última instalación en el formato que usa la página, o null si nunca hubo una.
     *
     * @return array<string, mixed>|null
     */
    public function status(): ?array
    {
        $state = $this->state();

        return $state === null ? null : $this->present($state);
    }

    public function isActive(): bool
    {
        $state = $this->state();

        return $state !== null && ! in_array($state['phase'], self::TERMINAL, true);
    }

    /**
     * @return array<string, mixed> El avance (ver present()).
     *
     * @throws RuntimeException Si ya hay una instalación en curso o el paquete no se puede instalar.
     */
    public function start(string $version, int $userId): array
    {
        if ($this->isActive()) {
            throw new RuntimeException('Ya hay una instalación en curso: termínala o revírtela antes de empezar otra.');
        }

        $report = app(InspectUpdate::class)->handle($version);

        if (! $report['ok']) {
            throw new RuntimeException('El paquete no se puede instalar: '.implode(' ', $report['problems']));
        }

        UpdateStorage::protect($this->storage->directory);
        File::deleteDirectory($this->storage->work());
        File::ensureDirectoryExists($this->storage->work());

        $state = [
            'id' => (string) Str::uuid(),
            'version' => $version,
            'build' => (int) $report['summary']['build'],
            'previous_version' => $report['installed']['version'],
            'user_id' => $userId,
            'phase' => 'maintenance',
            'started_at' => gmdate('c'),
            'finished_at' => null,
            'backup' => null,
            'entries' => 0,
            'offset' => 0,
            'maintenance' => false,
            'swapped' => false,
            'migrate_started' => false,
            'journal' => [],
            'log' => [],
            'error' => null,
        ];

        $this->log($state, "Instalación de la versión {$version} (build {$state['build']}) sobre la {$state['previous_version']}.");
        $this->save($state);

        return $this->present($state);
    }

    /**
     * Hace el trabajo de la fase actual (o de parte de ella) y devuelve el avance.
     *
     * @return array<string, mixed>
     */
    public function step(): array
    {
        File::ensureDirectoryExists($this->storage->work());
        $lock = fopen($this->storage->work().DIRECTORY_SEPARATOR.'.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return [...$this->present($this->state() ?? ['phase' => 'maintenance', 'version' => '', 'log' => [], 'offset' => 0, 'entries' => 0, 'error' => null]), 'busy' => true];
        }

        try {
            $state = $this->state();

            if ($state === null) {
                throw new RuntimeException('No hay una instalación en curso.');
            }

            if (in_array($state['phase'], self::TERMINAL, true)) {
                return $this->present($state);
            }

            @set_time_limit(max(60, (int) config('release.step_seconds') * 8));
            ignore_user_abort(true);

            try {
                $this->advance($state);
            } catch (Throwable $exception) {
                $state = $this->fail($state, $exception->getMessage());
            }

            $this->save($state);

            return $this->present($state);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Revierte la instalación en curso por decisión del administrador (también sirve para cancelarla antes del reemplazo).
     *
     * @return array<string, mixed>
     */
    public function revert(string $reason = 'Revertida por el administrador.'): array
    {
        $state = $this->state();

        if ($state === null || in_array($state['phase'], self::TERMINAL, true)) {
            throw new RuntimeException('No hay una instalación en curso que revertir.');
        }

        $state = $this->fail($state, $reason);
        $this->save($state);

        return $this->present($state);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function advance(array &$state): void
    {
        match ($state['phase']) {
            'maintenance' => $this->maintenance($state),
            'backup' => $this->backup($state),
            'assemble' => $this->assemble($state),
            'extract' => $this->extract($state),
            'swap' => $this->swap($state),
            'migrate' => $this->migrate($state),
            'finish' => $this->finish($state),
            default => throw new RuntimeException("Fase desconocida «{$state['phase']}»."),
        };
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function maintenance(array &$state): void
    {
        $this->hooks->maintenanceOn();
        $state['maintenance'] = true;
        $state['phase'] = 'backup';
        $this->log($state, 'Aplicación en mantenimiento.');
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function backup(array &$state): void
    {
        $backup = $this->hooks->backupDatabase('antes-de-v'.$state['version']);
        $state['backup'] = $backup['file'];
        $state['phase'] = 'assemble';
        $this->log($state, 'Respaldo de la base de datos: '.basename($backup['file']).'.');
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function assemble(array &$state): void
    {
        $directory = $this->storage->versionInbox($state['version']);
        $manifest = json_decode((string) file_get_contents($directory.DIRECTORY_SEPARATOR.'manifest.json'), true);
        $zipPath = $this->zipPath();
        $output = fopen($zipPath, 'wb');

        foreach ($manifest['parts'] as $part) {
            $input = fopen($directory.DIRECTORY_SEPARATOR.basename((string) $part['file']), 'rb');
            stream_copy_to_stream($input, $output);
            fclose($input);
        }

        fclose($output);

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('El ZIP unido no se puede abrir.');
        }

        $inventory = json_decode((string) $zip->getFromName('RELEASE.json'), true)['files'] ?? null;
        $state['entries'] = $zip->numFiles;
        $zip->close();

        if (! is_array($inventory) || $inventory === []) {
            throw new RuntimeException('El paquete no trae su inventario de archivos (RELEASE.json).');
        }

        file_put_contents($this->inventoryFile(), json_encode($inventory, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        File::deleteDirectory($this->staging());
        File::ensureDirectoryExists($this->staging());

        $state['offset'] = 0;
        $state['phase'] = 'extract';
        $this->log($state, count($inventory).' archivos por extraer.');
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function extract(array &$state): void
    {
        $inventory = json_decode((string) file_get_contents($this->inventoryFile()), true);
        $zip = new ZipArchive;

        if ($zip->open($this->zipPath(), ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('El ZIP unido no se puede abrir.');
        }

        $deadline = microtime(true) + max(1, (int) config('release.step_seconds'));

        try {
            while ($state['offset'] < $state['entries']) {
                $this->extractEntry($zip, (string) $zip->getNameIndex($state['offset']), $inventory);
                $state['offset']++;

                if (microtime(true) > $deadline) {
                    break;
                }
            }
        } finally {
            $zip->close();
        }

        if ($state['offset'] >= $state['entries']) {
            @unlink($this->zipPath());
            $state['phase'] = 'swap';
            $this->log($state, 'Archivos extraídos y verificados.');
        }
    }

    /**
     * @param  array<string, string>  $inventory
     */
    private function extractEntry(ZipArchive $zip, string $name, array $inventory): void
    {
        if ($name === 'RELEASE.json' || str_ends_with($name, '/')) {
            return;
        }

        if (! isset($inventory[$name]) || $this->isUnsafe($name)) {
            throw new RuntimeException("El ZIP trae un archivo que no corresponde: {$name}");
        }

        // Los archivos protegidos (bootstrap/cache/.gitignore, por ejemplo) viajan en el paquete pero nunca se reemplazan.
        if (self::unitOf($name) === null) {
            return;
        }

        $target = $this->staging().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name);
        File::ensureDirectoryExists(dirname($target));

        $input = $zip->getStream($name);
        $output = fopen($target, 'wb');
        $hash = hash_init('sha256');

        while ($input !== false && ! feof($input)) {
            $chunk = fread($input, 1048576);

            if ($chunk === false || $chunk === '') {
                break;
            }

            hash_update($hash, $chunk);
            fwrite($output, $chunk);
        }

        if ($input !== false) {
            fclose($input);
        }

        fclose($output);

        if (! hash_equals((string) $inventory[$name], hash_final($hash))) {
            throw new RuntimeException("El archivo {$name} se extrajo alterado (su SHA-256 no coincide).");
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function swap(array &$state): void
    {
        $inventory = json_decode((string) file_get_contents($this->inventoryFile()), true);
        $newUnits = array_values(array_unique(array_filter(array_map(fn ($path): ?string => self::unitOf((string) $path), array_keys($inventory)))));
        $oldPaths = array_keys($this->storage->installed()['files'] ?? []);
        $oldUnits = array_values(array_unique(array_filter(array_map(fn ($path): ?string => self::unitOf((string) $path), $oldPaths))));
        $journal = [];

        try {
            foreach ($newUnits as $unit) {
                $this->placeUnit($unit, $journal);
            }

            foreach (array_diff($oldUnits, $newUnits) as $unit) {
                $this->removeUnit($unit, $journal);
            }
        } catch (Throwable $exception) {
            $this->undoJournal($journal);

            throw $exception;
        }

        $state['journal'] = $journal;
        $state['swapped'] = true;
        $state['phase'] = 'migrate';
        $this->log($state, count($newUnits).' carpetas o archivos reemplazados; lo anterior quedó en work/rollback.');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * @param  list<array{unit: string, had_old: bool, removed: bool}>  $journal
     */
    private function placeUnit(string $unit, array &$journal): void
    {
        $source = $this->staging().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $unit);
        $destination = $this->destination($unit);
        $hadOld = file_exists($destination) || is_link($destination);

        if ($hadOld) {
            $this->move($destination, $this->aside($unit), "«{$unit}» a la carpeta de reversión");
        }

        $journal[] = ['unit' => $unit, 'had_old' => $hadOld, 'removed' => false];

        $this->move($source, $destination, "la versión nueva de «{$unit}» a su lugar");
    }

    /**
     * @param  list<array{unit: string, had_old: bool, removed: bool}>  $journal
     */
    private function removeUnit(string $unit, array &$journal): void
    {
        $destination = $this->destination($unit);

        if (! file_exists($destination) && ! is_link($destination)) {
            return;
        }

        $this->move($destination, $this->aside($unit), "«{$unit}» (ya no existe en la versión nueva) a la carpeta de reversión");
        $journal[] = ['unit' => $unit, 'had_old' => true, 'removed' => true];
    }

    /**
     * @param  list<array{unit: string, had_old: bool, removed: bool}>  $journal
     */
    private function undoJournal(array $journal): void
    {
        foreach (array_reverse($journal) as $entry) {
            $destination = $this->destination($entry['unit']);
            $aside = $this->aside($entry['unit']);

            if (! $entry['removed'] && (file_exists($destination) || is_link($destination))) {
                $failed = $this->storage->work().DIRECTORY_SEPARATOR.'failed'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry['unit']);
                $this->discard($failed);
                $this->move($destination, $failed, "la versión nueva de «{$entry['unit']}» fuera de su lugar");
            }

            if ($entry['had_old'] && (file_exists($aside) || is_link($aside))) {
                $this->move($aside, $destination, "«{$entry['unit']}» a su lugar original");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function migrate(array &$state): void
    {
        $state['migrate_started'] = true;
        $this->save($state);

        $output = $this->hooks->migrate();
        $state['phase'] = 'finish';
        $this->log($state, 'Migraciones aplicadas.'.($output === '' ? '' : ' '.Str::limit(preg_replace('/\s+/', ' ', $output) ?? '', 200)));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function finish(array &$state): void
    {
        $installedVersion = $this->storage->installedVersion();

        if ($installedVersion !== $state['version']) {
            throw new RuntimeException("Tras el reemplazo el archivo VERSION dice {$installedVersion} y debería decir {$state['version']}.");
        }

        $this->hooks->clearCaches();

        $inventory = json_decode((string) file_get_contents($this->inventoryFile()), true);
        File::ensureDirectoryExists($this->storage->directory);
        file_put_contents($this->storage->installedFile(), json_encode([
            'version' => $state['version'],
            'build' => $state['build'],
            'installed_at' => gmdate('c'),
            'files' => $inventory,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->hooks->maintenanceOff();
        $state['maintenance'] = false;
        File::deleteDirectory($this->staging());

        $state['phase'] = 'done';
        $state['finished_at'] = gmdate('c');
        $this->log($state, "Versión {$state['version']} instalada. Las carpetas anteriores quedan en work/rollback hasta la próxima instalación.");
        $this->history($state, 'instalada');
    }

    /**
     * Revierte lo hecho hasta ahora: base de datos (si ya se migró), carpetas movidas y mantenimiento.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function fail(array $state, string $reason): array
    {
        $state['error'] = $reason;
        $this->log($state, "Falló: {$reason}");

        try {
            if ($state['migrate_started'] && $state['backup']) {
                $this->hooks->restoreDatabase($state['backup']);
                $this->log($state, 'Base de datos restaurada desde el respaldo.');
            }

            if ($state['swapped']) {
                $this->undoJournal($state['journal']);
                $state['swapped'] = false;
                $this->log($state, 'Carpetas anteriores devueltas a su lugar.');
            }

            if ($state['maintenance']) {
                $this->hooks->clearCaches();
                $this->hooks->maintenanceOff();
                $state['maintenance'] = false;
            }

            File::deleteDirectory($this->staging());
            @unlink($this->zipPath());

            $state['phase'] = 'rolled_back';
            $this->log($state, 'La aplicación quedó como estaba.');
            $this->history($state, 'revertida: '.$reason);
        } catch (Throwable $exception) {
            $state['phase'] = 'rollback_failed';
            $state['error'] .= ' | La reversión también falló: '.$exception->getMessage();
            $this->log($state, 'La reversión falló: '.$exception->getMessage().' Las carpetas originales están en work/rollback.');
            $this->history($state, 'reversión fallida');
        }

        $state['finished_at'] = gmdate('c');

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function present(array $state): array
    {
        $phase = (string) $state['phase'];
        $order = ['maintenance' => 0, 'backup' => 1, 'assemble' => 2, 'extract' => 3, 'swap' => 4, 'migrate' => 5, 'finish' => 6];
        $extract = ($state['entries'] ?? 0) > 0 ? ($state['offset'] ?? 0) / $state['entries'] : 0;

        $percent = match (true) {
            $phase === 'done' => 100,
            ! isset($order[$phase]) => 0,
            $phase === 'extract' => (int) (30 + 40 * $extract),
            default => (int) (($order[$phase] / 7) * 100),
        };

        return [
            'id' => $state['id'] ?? null,
            'version' => $state['version'],
            'phase' => $phase,
            'label' => self::LABELS[$phase] ?? $phase,
            'percent' => min(100, $percent),
            'extracted' => $state['offset'] ?? 0,
            'entries' => $state['entries'] ?? 0,
            'finished' => in_array($phase, self::TERMINAL, true),
            'ok' => $phase === 'done',
            'error' => $state['error'] ?? null,
            'backup' => isset($state['backup']) ? basename((string) $state['backup']) : null,
            'user_id' => $state['user_id'] ?? null,
            'log' => array_slice($state['log'] ?? [], -40),
            'busy' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function log(array &$state, string $text): void
    {
        $state['log'][] = ['at' => gmdate('H:i:s'), 'text' => $text];
        $state['log'] = array_slice($state['log'], -100);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function save(array $state): void
    {
        File::ensureDirectoryExists($this->storage->work());
        $temporary = $this->stateFile().'.tmp';
        file_put_contents($temporary, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        rename($temporary, $this->stateFile());
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function history(array $state, string $result): void
    {
        File::ensureDirectoryExists($this->storage->directory);
        file_put_contents(
            $this->storage->directory.DIRECTORY_SEPARATOR.'history.log',
            sprintf("%s  v%s  build %s  desde v%s  usuario %s  %s\n", gmdate('Y-m-d H:i:s'), $state['version'], $state['build'], $state['previous_version'], $state['user_id'], $result),
            FILE_APPEND,
        );
    }

    private function move(string $from, string $to, string $what): void
    {
        File::ensureDirectoryExists(dirname($to));

        if (! @rename($from, $to)) {
            throw new RuntimeException("No se pudo mover {$what} (¿permisos o carpeta en uso?).");
        }
    }

    private function discard(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            File::deleteDirectory($path);
        } elseif (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
    }

    private function isUnsafe(string $name): bool
    {
        return str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_starts_with($name, '/')
            || preg_match('/^[A-Za-z]:/', $name) === 1
            || in_array('..', explode('/', $name), true);
    }

    private function destination(string $unit): string
    {
        return $this->storage->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $unit);
    }

    private function aside(string $unit): string
    {
        return $this->storage->work().DIRECTORY_SEPARATOR.'rollback'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $unit);
    }

    private function staging(): string
    {
        return $this->storage->work().DIRECTORY_SEPARATOR.'staging';
    }

    private function zipPath(): string
    {
        return $this->storage->work().DIRECTORY_SEPARATOR.'package.zip';
    }

    private function inventoryFile(): string
    {
        return $this->storage->work().DIRECTORY_SEPARATOR.'inventory.json';
    }

    private function stateFile(): string
    {
        return $this->storage->work().DIRECTORY_SEPARATOR.'state.json';
    }
}
