<?php

namespace App\Console\Commands;

use App\Actions\CheckSigningKey;
use App\Actions\SetEnvValue;
use Dotenv\Dotenv;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

/**
 * Registra en el .env (RELEASE_SIGNING_KEY_FILE) el archivo de clave privada que usará release:package. Cambia
 * únicamente esa variable (ver App\Actions\SetEnvValue) y nunca muestra los demás valores del .env.
 *
 * Antes de tocar nada comprueba la clave (App\Actions\CheckSigningKey) y muestra el cambio; después de editar,
 * vuelve a leer el .env y, si no quedó como debía, restaura la copia. La copia del .env contiene secretos, así que
 * se guarda FUERA del proyecto, junto a las claves: {carpeta de claves}/env-backups/{fecha}-env.bak
 *
 * USO: php artisan release:use-key "D:\claves\proy03_keys\private\20261120-20-53-release-private.key"
 *      php artisan release:use-key <archivo> --dry-run   solo muestra el cambio
 *      php artisan release:use-key <archivo> --yes       sin pedir confirmación (lo usa scripts/release.ps1)
 */
#[Signature('release:use-key
    {path : Archivo de la clave privada}
    {--backup-dir= : Carpeta para la copia del .env (por omisión, env-backups junto a la carpeta private)}
    {--dry-run : Solo muestra el cambio, sin escribir nada}
    {--yes : No pide confirmación}')]
#[Description('Registra la clave de firma en el .env (con copia previa, comprobación y restauración si falla)')]
class ReleaseUseKey extends Command
{
    private const VARIABLE = 'RELEASE_SIGNING_KEY_FILE';

    public function handle(CheckSigningKey $check, SetEnvValue $setEnv): int
    {
        $path = realpath((string) $this->argument('path'));
        $envPath = base_path('.env');

        if ($path === false || ! is_file($envPath)) {
            $this->components->error($path === false ? 'No existe el archivo de clave indicado.' : 'No existe el .env del proyecto.');

            return self::FAILURE;
        }

        $rows = $check->handle($path, (string) config('release.public_key_file'));

        if (! CheckSigningKey::passes($rows)) {
            foreach ($rows as $row) {
                $row['ok'] || $this->components->error("{$row['label']}: {$row['detail']}");
            }

            return self::FAILURE;
        }

        $current = $this->currentValue($envPath);

        $this->line('Cambio en el .env (solo esta variable):');
        $this->line('  '.self::VARIABLE.': '.($current === null ? '(sin definir)' : $current));
        $this->line('  '.self::VARIABLE.": {$path}");

        if ($current === $path) {
            $this->components->info('El .env ya apunta a esa clave: no hay nada que cambiar.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm('¿Aplicar el cambio?', true)) {
            $this->components->warn('Cancelado: el .env no se tocó.');

            return self::FAILURE;
        }

        $backup = $this->backup($envPath, $path);

        try {
            $setEnv->handle($envPath, self::VARIABLE, $path);

            if ($this->currentValue($envPath) !== $path) {
                throw new RuntimeException('el .env no quedó como se esperaba');
            }
        } catch (InvalidArgumentException|RuntimeException $exception) {
            File::copy($backup, $envPath);
            $this->components->error("No se pudo editar el .env ({$exception->getMessage()}); se restauró la copia.");

            return self::FAILURE;
        }

        $this->components->info('El .env ya apunta a la clave de firma.');
        $this->components->twoColumnDetail('Copia del .env anterior', $backup);

        return self::SUCCESS;
    }

    /**
     * Lee el valor tal como lo interpretaría Laravel, o null si no está definido.
     */
    private function currentValue(string $envPath): ?string
    {
        $values = Dotenv::parse((string) file_get_contents($envPath));

        return isset($values[self::VARIABLE]) && $values[self::VARIABLE] !== '' ? (string) $values[self::VARIABLE] : null;
    }

    private function backup(string $envPath, string $keyPath): string
    {
        $directory = (string) ($this->option('backup-dir') ?: dirname($keyPath, 2).DIRECTORY_SEPARATOR.'env-backups');
        File::ensureDirectoryExists($directory);

        $backup = $directory.DIRECTORY_SEPARATOR.now()->format('Ymd-H-i-s').'-env.bak';
        File::copy($envPath, $backup);

        return $backup;
    }
}
