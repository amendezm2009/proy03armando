<?php

namespace App\Actions;

use Illuminate\Support\Facades\Artisan;

/**
 * Lo que RunUpdate necesita del resto de la aplicación y que no es mover archivos: poner y quitar el modo de
 * mantenimiento, respaldar y restaurar la base de datos, migrar y limpiar cachés. Está aparte para que las pruebas
 * de RunUpdate puedan sustituirlo y no toquen la base de datos ni el modo de mantenimiento reales.
 *
 * El modo de mantenimiento deja pasar solo /admin/actualizaciones* (ver bootstrap/app.php), para que el
 * administrador pueda seguir la instalación y revertirla.
 */
class UpdateHooks
{
    public function maintenanceOn(): void
    {
        Artisan::call('down', ['--retry' => 60]);
    }

    public function maintenanceOff(): void
    {
        Artisan::call('up');
    }

    /**
     * @return array{file: string}
     */
    public function backupDatabase(string $label): array
    {
        return app(BackupDatabase::class)->handle(null, null, $label);
    }

    public function restoreDatabase(string $file): void
    {
        app(RestoreDatabase::class)->handle($file, null, false);
    }

    /**
     * @return string Lo que escribió el comando migrate.
     */
    public function migrate(): string
    {
        Artisan::call('migrate', ['--force' => true]);

        return trim(Artisan::output());
    }

    public function clearCaches(): void
    {
        Artisan::call('optimize:clear');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }
}
