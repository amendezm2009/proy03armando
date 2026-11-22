<?php

namespace App\Console\Commands;

use App\Actions\VerifyReleasePackage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Comprueba una versión empaquetada: firma, partes, ZIP unido y cada archivo (ver App\Actions\VerifyReleasePackage).
 * Termina con error si algo no cuadra, así sirve en los scripts de publicación.
 *
 * USO: php artisan release:verify releases/v1.2.0
 *      php artisan release:verify releases/v1.2.0 --quick   solo firma y partes, sin abrir el ZIP
 */
#[Signature('release:verify {directory : Carpeta de la versión (con manifest.json)} {--quick : Solo firma y hashes de las partes, sin abrir el ZIP}')]
#[Description('Verifica la firma y la integridad de una versión empaquetada')]
class ReleaseVerify extends Command
{
    public function handle(VerifyReleasePackage $verify): int
    {
        try {
            $manifest = $verify->handle((string) $this->argument('directory'), VerifyReleasePackage::trustedKeys(), deep: ! $this->option('quick'));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Versión {$manifest['version']} verificada: firma válida (clave {$manifest['verified_with']}), ".count($manifest['parts']).' parte(s)'.($this->option('quick') ? '.' : " y {$manifest['package']['files']} archivos con el SHA-256 correcto."));

        return self::SUCCESS;
    }
}
