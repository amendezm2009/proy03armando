<?php

namespace App\Console\Commands;

use App\Actions\BackupDatabase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Lista los respaldos de la base de datos, del más reciente al más antiguo.
 *
 * USO: php artisan db:backups
 */
#[Signature('db:backups')]
#[Description('Lista los respaldos de la base de datos guardados')]
class DbBackups extends Command
{
    public function handle(BackupDatabase $backup): int
    {
        $backups = $backup->list();

        if ($backups === []) {
            $this->components->info('No hay respaldos en '.config('backup.directory').'. Crea uno con php artisan db:backup.');

            return self::SUCCESS;
        }

        $this->table(
            ['Archivo', 'Fecha (UTC)', 'Versión', 'Filas', 'Tamaño'],
            array_map(fn (array $item): array => [
                $item['file'],
                (string) ($item['created_at'] ?? ''),
                (string) ($item['app_version'] ?? ''),
                (string) ($item['rows'] ?? ''),
                number_format(((int) ($item['size'] ?? 0)) / 1048576, 2).' MiB',
            ], $backups),
        );

        return self::SUCCESS;
    }
}
