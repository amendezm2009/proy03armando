<?php

namespace App\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Guarda una copia de la base de datos en PHP puro (sin mysqldump, que los hostings compartidos no ofrecen):
 * por cada tabla, DROP + CREATE y los datos en bloques de INSERT. Funciona con MySQL/MariaDB y SQLite.
 *
 * Resultado en la carpeta de config('backup.directory'):
 *   20261121-103045-v1.2.0-tienda.sql.gz       el volcado (sin comprimir si el servidor no tiene zlib)
 *   20261121-103045-v1.2.0-tienda.sql.gz.json  SHA-256, tamaño, versión de la app, controlador y tablas
 *
 * El .json es lo que permite a App\Actions\RestoreDatabase comprobar que el volcado no se alteró ni se truncó antes
 * de tocar la base de datos. De las tablas de config('backup.exclude_data') (sesiones, caché) solo se guarda la
 * estructura. Después de cada respaldo se borran los más antiguos, dejando config('backup.keep').
 *
 * USO: $respaldo = app(BackupDatabase::class)->handle();                       // conexión por omisión
 *      $respaldo = app(BackupDatabase::class)->handle(label: 'antes-de-actualizar');
 */
class BackupDatabase
{
    public const FORMAT = 1;

    private const DRIVERS = ['mysql', 'mariadb', 'sqlite'];

    /**
     * Una tabla que MySQL lista pero no puede abrir (error 42S02, p. ej. archivo .ibd faltante) no se puede respaldar:
     * se omite, se anota en "skipped" (y en el .json) y el respaldo sigue. Cualquier otro error detiene el respaldo.
     *
     * @return array{file: string, metadata: string, tables: int, rows: int, size: int, sha256: string, skipped: list<array{name: string, reason: string}>}
     */
    public function handle(?string $connection = null, ?string $directory = null, string $label = ''): array
    {
        $db = DB::connection($connection);
        $driver = $db->getDriverName();

        if (! in_array($driver, self::DRIVERS, true)) {
            throw new RuntimeException("El respaldo solo admite MySQL/MariaDB y SQLite (esta conexión usa «{$driver}»).");
        }

        $directory ??= (string) config('backup.directory');
        UpdateStorage::protect($directory);

        $compress = function_exists('gzopen');
        $path = $this->freePath($directory, (string) $db->getDatabaseName(), $label, $compress);

        $handle = $compress ? gzopen($path, 'wb6') : fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("No se pudo crear {$path}.");
        }

        $write = fn (string $text): int|false => $compress ? gzwrite($handle, $text) : fwrite($handle, $text);
        $pdo = $db->getPdo();
        $grammar = $db->getQueryGrammar();
        $excluded = (array) config('backup.exclude_data');
        $perInsert = max(1, (int) config('backup.rows_per_insert'));
        $tables = [];
        $skipped = [];
        $totalRows = 0;

        try {
            $write("-- Respaldo de la base de datos\n-- format: ".self::FORMAT."\n-- driver: {$driver}\n-- app_version: ".config('app.version')."\n-- created_at: ".gmdate('c')."\n\n");
            $write($driver === 'sqlite' ? "PRAGMA foreign_keys=OFF;\n\n" : "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

            // En MySQL, getTables() sin esquema devuelve las tablas de TODAS las bases del servidor: se limita a la actual.
            $schema = $driver === 'sqlite' ? null : $db->getDatabaseName();
            $names = [];

            foreach (Schema::connection($connection)->getTables($schema) as $table) {
                $name = (string) $table['name'];

                if (isset($names[$name])) {
                    continue;
                }

                $names[$name] = true;
                $wrapped = $grammar->wrapTable($name);
                $withData = ! in_array($name, $excluded, true);
                $rows = 0;

                try {
                    $create = $this->createStatements($db, $name, $driver);
                } catch (QueryException $exception) {
                    if ($exception->getCode() !== '42S02') {
                        throw $exception;
                    }

                    $skipped[] = ['name' => $name, 'reason' => 'figura en la base de datos pero MySQL no puede abrirla (¿archivo de la tabla dañado o faltante?)'];

                    continue;
                }

                $write("DROP TABLE IF EXISTS {$wrapped};\n{$create}\n");

                if ($withData) {
                    $batch = [];
                    $columns = null;

                    foreach ($db->table($name)->cursor() as $row) {
                        $row = (array) $row;
                        $columns ??= implode(', ', array_map(fn ($column): string => $grammar->wrap((string) $column), array_keys($row)));
                        $batch[] = '('.implode(', ', array_map(fn ($value): string => $this->literal($pdo, $value), $row)).')';
                        $rows++;

                        if (count($batch) >= $perInsert) {
                            $write("INSERT INTO {$wrapped} ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
                            $batch = [];
                        }
                    }

                    if ($batch !== []) {
                        $write("INSERT INTO {$wrapped} ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
                    }
                }

                $write("\n");
                $tables[] = ['name' => $name, 'rows' => $rows, 'data' => $withData];
                $totalRows += $rows;
            }

            $write($driver === 'sqlite' ? "PRAGMA foreign_keys=ON;\n" : "SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (Throwable $exception) {
            $compress ? gzclose($handle) : fclose($handle);
            @unlink($path);

            throw $exception;
        }

        $compress ? gzclose($handle) : fclose($handle);

        $metadata = [
            'format' => self::FORMAT,
            'created_at' => gmdate('c'),
            'app_version' => (string) config('app.version'),
            'driver' => $driver,
            'database' => basename((string) $db->getDatabaseName()),
            'label' => $label,
            'file' => basename($path),
            'size' => filesize($path),
            'sha256' => hash_file('sha256', $path),
            'rows' => $totalRows,
            'tables' => $tables,
            'skipped' => $skipped,
        ];

        file_put_contents($path.'.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

        $this->prune($directory, (int) config('backup.keep'));

        return ['file' => $path, 'metadata' => $path.'.json', 'tables' => count($tables), 'rows' => $totalRows, 'size' => $metadata['size'], 'sha256' => $metadata['sha256'], 'skipped' => $skipped];
    }

    /**
     * Los respaldos de una carpeta, del más reciente al más antiguo, según sus archivos .json.
     *
     * @return list<array<string, mixed>> Cada uno con sus datos y la ruta del volcado en "path".
     */
    public function list(?string $directory = null): array
    {
        $directory ??= (string) config('backup.directory');
        $backups = [];

        foreach (glob(rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'*.sql*.json') ?: [] as $metadataFile) {
            $data = json_decode((string) file_get_contents($metadataFile), true);

            if (is_array($data) && isset($data['file'])) {
                $data['path'] = substr($metadataFile, 0, -5);
                $backups[] = $data;
            }
        }

        $order = fn (array $backup): string => substr((string) $backup['file'], 0, 15).sprintf('%05d', preg_match('/-copia(\d+)\.sql/', (string) $backup['file'], $found) ? $found[1] : 1);

        usort($backups, fn (array $a, array $b): int => strcmp($order($b), $order($a)));

        return $backups;
    }

    private function prune(string $directory, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        foreach (array_slice($this->list($directory), $keep) as $old) {
            @unlink($old['path']);
            @unlink($old['path'].'.json');
        }
    }

    private function freePath(string $directory, string $database, string $label, bool $compress): string
    {
        $base = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.now()->format('Ymd-His').'-v'.config('app.version').'-'.$this->safe(pathinfo($database, PATHINFO_FILENAME) ?: 'bd').($label === '' ? '' : '-'.$this->safe($label));
        $extension = '.sql'.($compress ? '.gz' : '');
        $path = $base.$extension;

        for ($copy = 2; file_exists($path); $copy++) {
            $path = "{$base}-copia{$copy}{$extension}";
        }

        return $path;
    }

    private function safe(string $text): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $text), '-.') ?: 'x';
    }

    /**
     * La instrucción CREATE TABLE (y, en SQLite, sus índices), cada una terminada en «;» y salto de línea.
     */
    private function createStatements(mixed $db, string $table, string $driver): string
    {
        if ($driver === 'sqlite') {
            $statements = [];

            foreach ($db->select("select sql from sqlite_master where tbl_name = ? and sql is not null and type in ('table', 'index') order by type desc, name", [$table]) as $row) {
                $statements[] = rtrim((string) $row->sql, "; \n").';';
            }

            return implode("\n", $statements)."\n";
        }

        $row = (array) $db->selectOne('SHOW CREATE TABLE '.$db->getQueryGrammar()->wrapTable($table));

        return rtrim((string) ($row['Create Table'] ?? ''), "; \n").";\n";
    }

    private function literal(PDO $pdo, mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => var_export($value, true),
            default => (string) $pdo->quote((string) $value),
        };
    }
}
