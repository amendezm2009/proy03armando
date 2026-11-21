<?php

namespace App\Actions;

use RuntimeException;
use ZipArchive;

/**
 * Comprueba que una versión empaquetada (App\Actions\BuildReleasePackage) es auténtica y está completa. Recorre la
 * cadena de confianza completa y se detiene en el primer eslabón roto con un mensaje que dice cuál:
 *
 *   1. firma Ed25519 de manifest.json con la clave pública (config/release.php)
 *   2. tamaño y SHA-256 de cada parte, y SHA-256 del ZIP que resulta de unirlas
 *   3. (con $deep) el ZIP unido: RELEASE.json coincide con la versión, ningún nombre intenta salirse de la carpeta de
 *      destino (zip-slip), no sobra ni falta ningún archivo y cada uno tiene el SHA-256 anotado
 *
 * Lo usan php artisan release:verify y release:package (al terminar) y lo usará el actualizador del servidor
 * antes de extraer nada.
 *
 * USO: $manifest = app(VerifyReleasePackage::class)->handle(base_path('releases/v1.2.0'), config('release.public_key'));
 *
 * @throws RuntimeException Con el motivo en español si algo no cuadra.
 */
class VerifyReleasePackage
{
    private const CHUNK = 1048576;

    /**
     * @return array<string, mixed> El manifiesto ya verificado.
     */
    public function handle(string $directory, ?string $publicKey, bool $deep = true): array
    {
        $manifest = $this->verifiedManifest($directory, $publicKey);
        $assembled = $deep ? tempnam(sys_get_temp_dir(), 'rel') : null;

        try {
            $this->verifyParts($directory, $manifest, $assembled);

            if ($assembled !== null) {
                $this->verifyArchive($assembled, $manifest);
            }
        } finally {
            if ($assembled !== null && is_file($assembled)) {
                unlink($assembled);
            }
        }

        return $manifest;
    }

    /**
     * @return array<string, mixed>
     */
    private function verifiedManifest(string $directory, ?string $publicKey): array
    {
        if (! extension_loaded('sodium')) {
            throw new RuntimeException('Falta la extensión PHP «sodium» para comprobar la firma.');
        }

        $key = $publicKey === null ? false : base64_decode(trim($publicKey), true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('No hay una clave pública válida (config/release-public.key; créala con php artisan release:keygen).');
        }

        $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        $signaturePath = $manifestPath.'.sig';

        if (! is_file($manifestPath) || ! is_file($signaturePath)) {
            throw new RuntimeException('Faltan manifest.json o manifest.json.sig en la carpeta de la versión.');
        }

        $bytes = (string) file_get_contents($manifestPath);
        $signature = base64_decode(trim((string) file_get_contents($signaturePath)), true);

        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || ! sodium_crypto_sign_verify_detached($signature, $bytes, $key)) {
            throw new RuntimeException('La firma del manifiesto no es válida: el paquete no viene de quien tiene la clave privada o fue alterado.');
        }

        $manifest = json_decode($bytes, true);

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== BuildReleasePackage::FORMAT
            || ! BumpVersion::isValid((string) ($manifest['version'] ?? ''))
            || ! is_array($manifest['parts'] ?? null) || $manifest['parts'] === []
            || ! isset($manifest['package']['size'], $manifest['package']['sha256'])) {
            throw new RuntimeException('El manifiesto está firmado pero tiene un formato que esta versión de la aplicación no entiende.');
        }

        return $manifest;
    }

    /**
     * Verifica cada parte y, de paso, el SHA-256 del ZIP completo. Si $assembled no es null, escribe ahí el ZIP unido.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function verifyParts(string $directory, array $manifest, ?string $assembled): void
    {
        $output = $assembled === null ? null : fopen($assembled, 'wb');
        $whole = hash_init('sha256');
        $total = 0;

        foreach ($manifest['parts'] as $part) {
            $name = (string) ($part['file'] ?? '');

            if ($name === '' || basename($name) !== $name) {
                throw new RuntimeException("El nombre de parte «{$name}» no es válido.");
            }

            $path = $directory.DIRECTORY_SEPARATOR.$name;

            if (! is_file($path)) {
                throw new RuntimeException("Falta la parte {$name}.");
            }

            if (filesize($path) !== (int) $part['size']) {
                throw new RuntimeException("La parte {$name} no tiene el tamaño esperado (¿se subió incompleta?).");
            }

            $partHash = hash_init('sha256');
            $input = fopen($path, 'rb');

            while (! feof($input)) {
                $chunk = fread($input, self::CHUNK);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                hash_update($partHash, $chunk);
                hash_update($whole, $chunk);
                $total += strlen($chunk);

                if ($output !== null) {
                    fwrite($output, $chunk);
                }
            }

            fclose($input);

            if (! hash_equals((string) $part['sha256'], hash_final($partHash))) {
                throw new RuntimeException("La parte {$name} está dañada: su SHA-256 no coincide con el manifiesto.");
            }
        }

        if ($output !== null) {
            fclose($output);
        }

        if ($total !== (int) $manifest['package']['size'] || ! hash_equals((string) $manifest['package']['sha256'], hash_final($whole))) {
            throw new RuntimeException('Las partes unidas no forman el ZIP anunciado en el manifiesto (faltan, sobran o están en otro orden).');
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function verifyArchive(string $zipPath, array $manifest): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('El ZIP unido no se puede abrir.');
        }

        try {
            $inventory = json_decode((string) $zip->getFromName('RELEASE.json'), true);

            if (! is_array($inventory) || ($inventory['version'] ?? null) !== $manifest['version'] || ! is_array($inventory['files'] ?? null)) {
                throw new RuntimeException('El RELEASE.json del ZIP falta o es de otra versión.');
            }

            $pending = $inventory['files'];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);

                if ($name === 'RELEASE.json' || str_ends_with($name, '/')) {
                    continue;
                }

                if ($this->isUnsafeEntry($name)) {
                    throw new RuntimeException("El ZIP contiene una ruta peligrosa: {$name}");
                }

                if (! isset($pending[$name])) {
                    throw new RuntimeException("El ZIP contiene un archivo que no figura en RELEASE.json: {$name}");
                }

                if (! hash_equals((string) $pending[$name], $this->entryHash($zip, $name))) {
                    throw new RuntimeException("El archivo {$name} está alterado: su SHA-256 no coincide con RELEASE.json.");
                }

                unset($pending[$name]);
            }

            if ($pending !== []) {
                throw new RuntimeException('Faltan en el ZIP archivos listados en RELEASE.json, por ejemplo: '.array_key_first($pending));
            }
        } finally {
            $zip->close();
        }
    }

    private function isUnsafeEntry(string $name): bool
    {
        return $name === ''
            || str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_starts_with($name, '/')
            || preg_match('/^[A-Za-z]:/', $name) === 1
            || in_array('..', explode('/', $name), true);
    }

    private function entryHash(ZipArchive $zip, string $name): string
    {
        $stream = $zip->getStream($name);

        if ($stream === false) {
            throw new RuntimeException("No se pudo leer {$name} dentro del ZIP.");
        }

        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        fclose($stream);

        return hash_final($hash);
    }
}
