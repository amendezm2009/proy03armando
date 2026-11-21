<?php

namespace App\Console\Commands;

use App\Actions\SigningKeyFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

/**
 * Crea el par de claves Ed25519 con el que se firman las versiones (ver config/release.php y
 * App\Actions\SigningKeyFile). Se ejecuta UNA vez, o al rotar claves.
 *
 * Deja, FUERA del proyecto y con la fecha en el nombre:
 *   {proyecto}_keys/private/20261120-20-53-release-private.key   protegida con contraseña; NUNCA al repositorio ni al servidor
 *   {proyecto}_keys/public/20261120-20-53-release-public.key     copia de la pública
 * y copia la pública a config/release-public.key, que sí se sube al repositorio (solo sirve para comprobar firmas).
 * Si ya existe una clave pública del proyecto, es una ROTACIÓN: la nueva se guarda en config/release-public-keys/ (la
 * aplicación confía en ambas) y la vigente sigue firmando hasta que cambies con release:use-key.
 *
 * Después: php artisan release:use-key <archivo privado> lo registra en el .env y hay que guardar una copia de la
 * carpeta de claves en otro lugar (USB, gestor de contraseñas o nube cifrada): sin la privada no se pueden firmar
 * versiones nuevas. No se envía por correo.
 *
 * USO: php artisan release:keygen
 *      php artisan release:keygen D:\claves\proy03_keys --no-copy
 */
#[Signature('release:keygen
    {directory? : Carpeta raíz de claves (por omisión, <proyecto>_keys junto a la carpeta del proyecto)}
    {--no-passphrase : Guarda la clave privada SIN contraseña (no recomendado)}
    {--no-copy : No copia la clave pública a config/release-public.key}
    {--force : Reemplaza config/release-public.key si ya existe (sin --force, la pública nueva se guarda en config/release-public-keys/ para rotar claves)}')]
#[Description('Crea el par de claves con el que se firman las versiones (fuera del proyecto, con contraseña)')]
class ReleaseKeygen extends Command
{
    public function handle(SigningKeyFile $keys): int
    {
        $directory = (string) ($this->argument('directory') ?: config('release.keys_directory'));

        if ($this->isInsideProject($directory)) {
            $this->components->error('La carpeta de claves debe quedar FUERA de la carpeta del proyecto, para que nunca llegue al repositorio ni al servidor.');

            return self::FAILURE;
        }

        $primary = (string) config('release.public_key_file');
        $rotation = ! $this->option('no-copy') && File::exists($primary) && ! $this->option('force');
        $publicCopy = $this->option('no-copy') || $rotation ? null : $primary;

        $passphrase = $this->option('no-passphrase') ? null : $this->passphrase();

        if (! $this->option('no-passphrase') && $passphrase === null) {
            return self::FAILURE;
        }

        try {
            $pair = $keys->handle($directory, $passphrase, $publicCopy, strength: (string) config('release.key_strength'));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->restrictPermissions(dirname($pair['private']), $pair['private']);

        $this->components->info('Par de claves creado.');
        $this->components->twoColumnDetail('Clave privada', $pair['private']);
        $this->components->twoColumnDetail('Clave pública', $pair['public']);
        $this->components->twoColumnDetail('Huella', $pair['fingerprint']);
        $this->components->twoColumnDetail('Protegida con contraseña', $pair['encrypted'] ? 'sí' : 'NO');

        if ($publicCopy !== null) {
            $this->components->twoColumnDetail('Copia para el repositorio', 'config/release-public.key (haz commit de ese archivo)');
        }

        if ($rotation) {
            $extra = rtrim((string) config('release.public_keys_directory'), '/\\').DIRECTORY_SEPARATOR.str_replace('-', '', $pair['fingerprint']).'.key';
            File::ensureDirectoryExists(dirname($extra));
            File::put($extra, trim((string) file_get_contents($pair['public']))."\n");

            $this->components->twoColumnDetail('Rotación', 'la pública nueva quedó en config/release-public-keys/'.basename($extra).' (haz commit)');
            $this->components->warn('La clave actual sigue siendo la vigente. Pasos: 1) publica una versión firmada con la clave ACTUAL que ya incluya ese archivo; 2) cuando los servidores la tengan instalada, usa la nueva con php artisan release:use-key. Con --force la nueva reemplaza a la principal (invalida las versiones ya firmadas).');
        }

        $this->components->warn('Guarda una copia de la carpeta de claves en otro lugar (USB, gestor de contraseñas o nube cifrada). Sin la privada no puedes firmar versiones. No la envíes por correo.');
        $this->line('Siguiente paso: php artisan release:use-key "'.$pair['private'].'"');

        return self::SUCCESS;
    }

    private function passphrase(): ?string
    {
        $given = config('release.key_passphrase');

        if (filled($given)) {
            return (string) $given;
        }

        $first = (string) $this->secret('Contraseña para proteger la clave privada (mínimo '.SigningKeyFile::MIN_PASSPHRASE_LENGTH.' caracteres)');
        $second = (string) $this->secret('Repite la contraseña');

        if ($first !== $second) {
            $this->components->error('Las contraseñas no coinciden.');

            return null;
        }

        if (mb_strlen($first) < SigningKeyFile::MIN_PASSPHRASE_LENGTH) {
            $this->components->error('La contraseña debe tener al menos '.SigningKeyFile::MIN_PASSPHRASE_LENGTH.' caracteres.');

            return null;
        }

        return $first;
    }

    /**
     * Deja la carpeta private/ y el archivo accesibles solo para tu usuario. Si no se logra, avisa pero no falla.
     */
    private function restrictPermissions(string $directory, string $file): void
    {
        if (! config('release.restrict_permissions')) {
            return;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($directory, 0700);
            @chmod($file, 0600);

            return;
        }

        $user = (string) getenv('USERNAME');
        $account = $user === '' ? '' : (getenv('USERDOMAIN') ? getenv('USERDOMAIN').'\\' : '').$user;

        if ($account === '') {
            $this->components->warn('No se pudo saber tu usuario de Windows: restringe a mano los permisos de la carpeta private.');

            return;
        }

        exec('icacls '.escapeshellarg($directory).' /inheritance:r /grant:r '.escapeshellarg($account.':(OI)(CI)F').' 2>&1', $output, $code);

        if ($code !== 0) {
            $this->components->warn('No se pudieron restringir los permisos de la carpeta private (icacls código '.$code.'). Hazlo a mano.');
        }
    }

    private function isInsideProject(string $directory): bool
    {
        $normalize = fn (string $path): string => strtolower(rtrim(str_replace('\\', '/', $path), '/')).'/';

        $existing = $directory;
        $rest = '';

        while ($existing !== '' && realpath($existing) === false && dirname($existing) !== $existing) {
            $rest = DIRECTORY_SEPARATOR.basename($existing).$rest;
            $existing = dirname($existing);
        }

        $resolved = (realpath($existing) ?: $existing).$rest;

        return str_starts_with($normalize($resolved), $normalize((string) realpath(base_path())));
    }
}
