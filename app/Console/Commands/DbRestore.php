<?php

namespace App\Console\Commands;

use App\Actions\BackupDatabase;
use App\Actions\RestoreDatabase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Restaura la base de datos desde un respaldo (ver App\Actions\RestoreDatabase): comprueba su SHA-256, guarda antes
 * un respaldo de seguridad del estado actual y, si la restauración falla, intenta volver a ese estado.
 *
 * USO: php artisan db:restore                              elige de la lista de respaldos
 *      php artisan db:restore 20261121-103045-v1.2.0-tienda.sql.gz
 *      php artisan db:restore <archivo> --yes              sin pedir confirmación (scripts)
 */
#[Signature('db:restore {file? : Archivo del respaldo (ruta completa o nombre dentro de la carpeta de respaldos)} {--connection= : Conexión a restaurar} {--yes : No pedir confirmación}')]
#[Description('Restaura la base de datos desde un respaldo, con verificación previa y respaldo de seguridad')]
class DbRestore extends Command
{
    public function handle(BackupDatabase $backup, RestoreDatabase $restore): int
    {
        $path = $this->resolvePath($backup);

        if ($path === null) {
            return self::FAILURE;
        }

        $connection = $this->option('connection') ?: null;
        $database = DB::connection($connection)->getDatabaseName();

        $this->components->warn("Restaurar REEMPLAZA las tablas de la base de datos «{$database}» con las del respaldo ".basename($path).'. Antes se guarda un respaldo de seguridad del estado actual.');
        $this->line('Si la aplicación está en uso, ponla en mantenimiento primero: php artisan down');

        if (! $this->option('yes') && ! $this->confirm('¿Restaurar ahora?', false)) {
            $this->components->info('Cancelado: la base de datos no se tocó.');

            return self::SUCCESS;
        }

        try {
            $result = $restore->handle($path, $connection);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Base de datos restaurada ({$result['statements']} instrucciones).");
        $this->components->twoColumnDetail('Respaldo', basename($path));
        $this->components->twoColumnDetail('Versión de la aplicación del respaldo', (string) ($result['metadata']['app_version'] ?? ''));
        $this->components->twoColumnDetail('Respaldo de seguridad previo', (string) $result['safety']);
        $this->line('Si la versión del respaldo es distinta a la instalada, ejecuta php artisan migrate --force y vuelve a poner la aplicación en línea: php artisan up');

        return self::SUCCESS;
    }

    private function resolvePath(BackupDatabase $backup): ?string
    {
        $given = (string) $this->argument('file');

        if ($given !== '') {
            $candidates = [$given, rtrim((string) config('backup.directory'), '/\\').DIRECTORY_SEPARATOR.$given];

            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }

            $this->components->error("No existe el respaldo «{$given}».");

            return null;
        }

        $backups = $backup->list();

        if ($backups === []) {
            $this->components->error('No hay respaldos en '.config('backup.directory').'.');

            return null;
        }

        $names = array_column($backups, 'file');
        $chosen = $this->choice('¿Qué respaldo restauro?', $names, 0);

        return $backups[array_search($chosen, $names, true)]['path'];
    }
}
