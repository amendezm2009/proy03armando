<?php

namespace App\Console\Commands;

use App\Actions\BuildReleasePackage;
use App\Actions\SigningKeyFile;
use App\Actions\VerifyReleasePackage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

/**
 * Empaqueta y firma una versión (ver App\Actions\BuildReleasePackage) y, antes de darla por buena, la verifica
 * igual que lo hará el servidor (App\Actions\VerifyReleasePackage). Si la verificación falla, borra lo generado.
 *
 * Lo normal es no ejecutarlo a mano: scripts/release.ps1 prepara la carpeta de origen (git archive + composer
 * install --no-dev + npm run build) y lo invoca con --source.
 *
 * USO: php artisan release:package 1.2.0 --source=C:\temp\staging --previous=1.1.0 --commit=abc1234
 */
#[Signature('release:package {version : Versión a publicar (MAYOR.MENOR.PARCHE); debe coincidir con VERSION del origen}
    {--source= : Carpeta ya preparada con el código, vendor/ sin dependencias de desarrollo y public/build (por omisión, el proyecto)}
    {--output= : Carpeta donde se crea v{versión}/ (por omisión, releases/)}
    {--key= : Archivo con la clave privada (por omisión, RELEASE_SIGNING_KEY_FILE)}
    {--previous= : Versión que reemplaza}
    {--commit= : Commit de git del que sale}
    {--build=1 : Número de build: 1 la primera vez que se genera la versión y +1 en cada regeneración}
    {--part-mb= : Tamaño máximo de cada parte, en MiB (por omisión, RELEASE_PART_SIZE_MB)}')]
#[Description('Empaqueta, parte en trozos y firma una versión de la aplicación')]
class ReleasePackage extends Command
{
    public function handle(BuildReleasePackage $build, VerifyReleasePackage $verify, SigningKeyFile $keys): int
    {
        $keyFile = (string) ($this->option('key') ?: config('release.signing_key_file'));

        if ($keyFile === '' || ! is_file($keyFile)) {
            $this->components->error('No se encontró la clave privada. Indícala con --key o RELEASE_SIGNING_KEY_FILE en el .env (se crea con php artisan release:keygen).');

            return self::FAILURE;
        }

        $version = (string) $this->argument('version');
        $output = (string) ($this->option('output') ?: base_path('releases'));
        $partMb = (int) ($this->option('part-mb') ?: config('release.part_size_mb'));

        try {
            $secretKey = $this->secretKey($keys, $keyFile);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $manifest = $build->handle(
                sourceDir: (string) ($this->option('source') ?: base_path()),
                outputDir: $output,
                version: $version,
                secretKey: $secretKey,
                partSize: max(1, $partMb) * 1024 * 1024,
                previousVersion: $this->option('previous') ?: null,
                commit: $this->option('commit') ?: null,
                build: (int) $this->option('build'),
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $releaseDir = rtrim($output, '/\\').DIRECTORY_SEPARATOR."v{$version}";

        try {
            $verify->handle($releaseDir, VerifyReleasePackage::trustedKeys());
        } catch (RuntimeException $exception) {
            File::deleteDirectory($releaseDir);
            $this->components->error('El paquete recién creado no pasa la verificación y se borró: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Versión {$version} empaquetada y verificada: {$releaseDir}");
        $this->components->twoColumnDetail('Build', (string) $manifest['build']);
        $this->components->twoColumnDetail('Archivos', (string) $manifest['package']['files']);
        $this->components->twoColumnDetail('Partes', (string) count($manifest['parts']));
        $this->components->twoColumnDetail('Tamaño del ZIP', number_format($manifest['package']['size'] / 1048576, 1).' MiB');
        $this->components->twoColumnDetail('SHA-256', $manifest['package']['sha256']);
        $this->components->twoColumnDetail('Huella de la clave', SigningKeyFile::fingerprint($keys->publicKeyOf($keyFile)));

        return self::SUCCESS;
    }

    /**
     * La clave privada en Base64: si el archivo está protegido, la contraseña sale de la configuración (para
     * automatizar) o se pide sin mostrarla en pantalla.
     */
    private function secretKey(SigningKeyFile $keys, string $keyFile): string
    {
        $passphrase = config('release.key_passphrase');

        if (! filled($passphrase) && $keys->isEncrypted($keyFile)) {
            $passphrase = (string) $this->secret('Contraseña de la clave de firma');
        }

        return $keys->read($keyFile, filled($passphrase) ? (string) $passphrase : null);
    }
}
