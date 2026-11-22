<?php

namespace App\Console\Commands;

use App\Actions\BackupDatabase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Guarda un respaldo de la base de datos (ver App\Actions\BackupDatabase) y lo anota con su SHA-256.
 *
 * USO: php artisan db:backup
 *      php artisan db:backup --label=antes-de-actualizar
 */
#[Signature('db:backup {--connection= : Conexión a respaldar (por omisión, la predeterminada)} {--label= : Texto corto que se agrega al nombre del archivo}')]
#[Description('Guarda un respaldo comprimido de la base de datos, con su SHA-256, en storage/app/private/backups')]
class DbBackup extends Command
{
    public function handle(BackupDatabase $backup): int
    {
        try {
            $result = $backup->handle($this->option('connection') ?: null, null, (string) $this->option('label'));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Respaldo creado.');
        $this->components->twoColumnDetail('Archivo', $result['file']);
        $this->components->twoColumnDetail('Tablas', (string) $result['tables']);
        $this->components->twoColumnDetail('Filas', (string) $result['rows']);
        $this->components->twoColumnDetail('Tamaño', number_format($result['size'] / 1048576, 2).' MiB');
        $this->components->twoColumnDetail('SHA-256', $result['sha256']);

        foreach ($result['skipped'] as $table) {
            $this->components->warn("Tabla omitida: {$table['name']} {$table['reason']}. El respaldo NO la incluye.");
        }

        return self::SUCCESS;
    }
}
