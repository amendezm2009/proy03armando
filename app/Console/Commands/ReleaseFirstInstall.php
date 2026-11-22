<?php

namespace App\Console\Commands;

use App\Actions\PrepareFirstInstall;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * Prepara en tu equipo todo lo necesario para instalar la aplicación por primera vez en un hosting sin SSH (ver
 * App\Actions\PrepareFirstInstall): sitio descomprimido y verificado, plantilla del .env de producción, base de datos
 * inicial con un administrador y las instrucciones. Lo normal es usar scripts\preparar-primera-instalacion.ps1.
 *
 * USO: php artisan release:first-install releases\v1.0.0 --admin-email=yo@example.com
 *      php artisan release:first-install releases\v1.0.0 --no-database
 */
#[Signature('release:first-install {package : Carpeta del paquete (releases/vX.Y.Z)}
    {--output= : Carpeta de salida (por omisión, releases/instalar-vX.Y.Z)}
    {--admin-name=Administrador : Nombre del administrador inicial}
    {--admin-email= : Correo del administrador inicial}
    {--no-database : No preparar la base de datos inicial}
    {--with-classifications : Incluir las clasificaciones de ejemplo en la base de datos}')]
#[Description('Prepara la primera instalación en un hosting sin SSH: sitio verificado, plantilla del .env y base de datos inicial')]
class ReleaseFirstInstall extends Command
{
    public function handle(PrepareFirstInstall $prepare): int
    {
        $package = rtrim((string) $this->argument('package'), '/\\');
        $withDatabase = ! $this->option('no-database');
        $email = (string) $this->option('admin-email');

        if ($withDatabase && $email === '' && $this->input->isInteractive()) {
            $email = (string) $this->ask('Correo del administrador inicial');
        }

        $output = (string) ($this->option('output') ?: dirname($package).DIRECTORY_SEPARATOR.'instalar-'.basename($package));

        try {
            $result = $prepare->handle($package, $output, [
                'admin_name' => (string) $this->option('admin-name'),
                'admin_email' => $email,
                'database' => $withDatabase,
                'classifications' => (bool) $this->option('with-classifications'),
            ]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Primera instalación de la versión {$result['version']} (build {$result['build']}) preparada.");
        $this->components->twoColumnDetail('Carpeta', dirname($result['site']));
        $this->components->twoColumnDetail('Sitio (subir por FTP)', $result['site']." ({$result['files']} archivos verificados)");
        $this->components->twoColumnDetail('Plantilla del .env', $result['env']);
        $this->components->twoColumnDetail('Base de datos inicial', $result['database'] ?? 'no preparada');
        $this->components->twoColumnDetail('Instrucciones', $result['readme']);

        if ($result['admin_password'] !== null) {
            $this->components->twoColumnDetail('Administrador', (string) $result['admin_email']);
            $this->components->warn('Contraseña inicial del administrador (se muestra UNA sola vez; guárdala en tu gestor de contraseñas y cámbiala al entrar): '.$result['admin_password']);
        }

        $this->components->warn('env-produccion.txt contiene secretos: súbelo como .env y bórralo de tu equipo.');

        return self::SUCCESS;
    }
}
