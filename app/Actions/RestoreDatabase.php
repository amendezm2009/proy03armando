<?php

namespace App\Actions;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Restaura la base de datos desde un respaldo hecho con App\Actions\BackupDatabase. Antes de tocar nada:
 *
 *   1. comprueba el .json del respaldo: tamaño y SHA-256 del volcado (un archivo alterado, truncado o sin su .json
 *      no se restaura)
 *   2. comprueba que el respaldo es del mismo tipo de base de datos que la conexión
 *   3. guarda un respaldo de seguridad del estado actual (etiqueta «antes-de-restaurar»)
 *
 * Después ejecuta el volcado instrucción por instrucción. Si algo falla a mitad, intenta devolver la base de datos al
 * estado que tenía (con el respaldo de seguridad) y lo dice en el mensaje. Restaurar BORRA y reemplaza las tablas
 * que contiene el respaldo: ponla en mantenimiento (php artisan down) mientras tanto.
 *
 * USO: $resultado = app(RestoreDatabase::class)->handle(storage_path('app/private/backups/20261121-103045-v1.2.0-tienda.sql.gz'));
 */
class RestoreDatabase
{
    /**
     * @return array{statements: int, safety: ?string, metadata: array<string, mixed>}
     *
     * @throws RuntimeException Con el motivo en español si algo no cuadra.
     */
    public function handle(string $file, ?string $connection = null, bool $safetyBackup = true): array
    {
        $metadata = $this->verifiedMetadata($file);
        $db = DB::connection($connection);

        if ($metadata['driver'] !== $db->getDriverName()) {
            throw new RuntimeException("El respaldo es de una base de datos {$metadata['driver']} y la conexión usa {$db->getDriverName()}.");
        }

        $safety = $safetyBackup ? app(BackupDatabase::class)->handle($connection, null, 'antes-de-restaurar') : null;

        try {
            $statements = $this->execute($file, $db);
        } catch (Throwable $exception) {
            throw new RuntimeException('La restauración falló: '.$exception->getMessage().$this->rollBack($safety, $connection), previous: $exception);
        }

        return ['statements' => $statements, 'safety' => $safety['file'] ?? null, 'metadata' => $metadata];
    }

    /**
     * @param  array{file: string}|null  $safety
     */
    private function rollBack(?array $safety, ?string $connection): string
    {
        if ($safety === null) {
            return '';
        }

        try {
            $this->handle($safety['file'], $connection, false);

            return ' La base de datos se devolvió al estado anterior.';
        } catch (Throwable) {
            return " NO se pudo devolver al estado anterior: restaura a mano {$safety['file']}.";
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function verifiedMetadata(string $file): array
    {
        if (! is_file($file)) {
            throw new RuntimeException("No existe el respaldo {$file}.");
        }

        if (! is_file($file.'.json')) {
            throw new RuntimeException('Falta '.basename($file).'.json junto al respaldo: sin él no se puede comprobar que esté íntegro.');
        }

        $metadata = json_decode((string) file_get_contents($file.'.json'), true);

        if (! is_array($metadata) || ($metadata['format'] ?? null) !== BackupDatabase::FORMAT || ! isset($metadata['sha256'], $metadata['size'], $metadata['driver'])) {
            throw new RuntimeException('El archivo .json del respaldo no tiene un formato que esta versión entienda.');
        }

        if (filesize($file) !== (int) $metadata['size'] || ! hash_equals((string) $metadata['sha256'], (string) hash_file('sha256', $file))) {
            throw new RuntimeException('El respaldo está dañado o alterado: su tamaño o SHA-256 no coincide con su .json.');
        }

        return $metadata;
    }

    /**
     * Ejecuta el volcado separando las instrucciones por «;» al final de línea fuera de las comillas simples
     * (los textos pueden contener «;» y saltos de línea). En MySQL, una barra invertida escapa la comilla siguiente.
     */
    private function execute(string $file, Connection $db): int
    {
        $compressed = function_exists('gzopen');
        $handle = $compressed ? gzopen($file, 'rb') : fopen($file, 'rb');

        if ($handle === false) {
            throw new RuntimeException("No se pudo abrir {$file}.");
        }

        $backslashEscapes = in_array($db->getDriverName(), ['mysql', 'mariadb'], true);
        $buffer = '';
        $inQuote = false;
        $count = 0;

        try {
            while (($line = $compressed ? gzgets($handle) : fgets($handle)) !== false) {
                if ($buffer === '' && ! $inQuote && (trim($line) === '' || str_starts_with(ltrim($line), '--'))) {
                    continue;
                }

                $buffer .= $line;

                for ($index = 0, $length = strlen($line); $index < $length; $index++) {
                    if ($inQuote && $backslashEscapes && $line[$index] === '\\') {
                        $index++;
                    } elseif ($line[$index] === "'") {
                        $inQuote = ! $inQuote;
                    }
                }

                if (! $inQuote && str_ends_with(rtrim($buffer), ';')) {
                    $db->unprepared($buffer);
                    $count++;
                    $buffer = '';
                }
            }
        } finally {
            $compressed ? gzclose($handle) : fclose($handle);
        }

        if (trim($buffer) !== '') {
            throw new RuntimeException('El respaldo termina en medio de una instrucción (archivo truncado).');
        }

        return $count;
    }
}
