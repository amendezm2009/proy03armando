<?php

namespace App\Console\Commands;

use App\Actions\CheckSigningKey;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Comprueba que la clave de firma está bien guardada y es del par cuya clave pública usa el proyecto (ver
 * App\Actions\CheckSigningKey). Termina con error si algo no cuadra. Nunca muestra la clave.
 *
 * USO: php artisan release:keycheck            revisión rápida de RELEASE_SIGNING_KEY_FILE (sin pedir la contraseña)
 *      php artisan release:keycheck --deep     además pide la contraseña, descifra y firma una prueba
 *      php artisan release:keycheck "D:\claves\proy03_keys\private\20261120-20-53-release-private.key"
 */
#[Signature('release:keycheck {path? : Archivo de la clave privada (por omisión, RELEASE_SIGNING_KEY_FILE)} {--deep : Pide la contraseña, descifra la clave y firma una prueba}')]
#[Description('Comprueba que la clave de firma está bien guardada y corresponde a la clave pública del proyecto')]
class ReleaseKeycheck extends Command
{
    public function handle(CheckSigningKey $check): int
    {
        $path = (string) ($this->argument('path') ?: config('release.signing_key_file'));

        if ($path === '') {
            $this->components->error('No hay clave de firma configurada: indica la ruta o ejecuta php artisan release:use-key <archivo>.');

            return self::FAILURE;
        }

        $passphrase = null;

        if ($this->option('deep')) {
            $passphrase = filled(config('release.key_passphrase')) ? (string) config('release.key_passphrase') : (string) $this->secret('Contraseña de la clave de firma');
        }

        $rows = $check->handle($path, (string) config('release.public_key_file'), $passphrase, (bool) $this->option('deep'));

        foreach ($rows as $row) {
            $row['ok']
                ? $this->components->twoColumnDetail($row['label'], '<fg=green>OK</> '.$row['detail'])
                : $this->components->twoColumnDetail($row['label'], '<fg=red>FALLA</> '.$row['detail']);
        }

        if (! CheckSigningKey::passes($rows)) {
            $this->components->error('La clave de firma no pasa la revisión.');

            return self::FAILURE;
        }

        $this->components->info('Clave de firma en orden.');

        return self::SUCCESS;
    }
}
