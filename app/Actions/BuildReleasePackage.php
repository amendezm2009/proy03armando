<?php

namespace App\Actions;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

/**
 * Empaqueta una versión de la aplicación para publicarla: un ZIP con el código (ya con vendor/ y public/build),
 * partido en partes pequeñas, más un manifiesto firmado que permite comprobar en el servidor que nada cambió.
 *
 * Resultado en {salida}/v{versión}/:
 *   app-v{versión}.zip.001, .002, ...  partes del ZIP (cada una de $partSize bytes como máximo)
 *   manifest.json                     versión, hashes SHA-256 de cada parte y del ZIP completo, migraciones, PHP mínimo
 *   manifest.json.sig                 firma Ed25519 (Base64) del manifest.json; ver config/release.php
 *
 * "build" cuenta las veces que se generó la misma versión: 1 la primera vez y +1 cada vez que se regenera sin cambiar
 * el número (así dos paquetes de la misma versión se distinguen aunque tengan el mismo código).
 *
 * Dentro del ZIP, RELEASE.json lista el SHA-256 de cada archivo. Así la cadena de confianza es: firma → manifest.json
 * → hash del ZIP → RELEASE.json → hash de cada archivo (App\Actions\VerifyReleasePackage recorre esa cadena).
 *
 * "signing_key" es la huella de la clave pública que debe verificar la firma (ver SigningKeyFile::fingerprint): sirve
 * para decir con claridad con qué clave está firmado un paquete y para elegir entre varias claves de confianza.
 *
 * Nunca se empaquetan: .git, .env*, *release-private.key, node_modules, tests, storage (los datos del servidor no se reemplazan), logs ni
 * la caché de bootstrap/cache.
 *
 * USO: app(BuildReleasePackage::class)->handle($carpetaPreparada, base_path('releases'), '1.2.0', $claveSecretaBase64, 9 * 1024 * 1024)
 */
class BuildReleasePackage
{
    public const FORMAT = 1;

    /** Carpetas de la raíz que nunca viajan en el paquete. */
    private const EXCLUDED_ROOT_DIRECTORIES = ['.git', '.github', 'node_modules', 'tests', 'releases', 'storage'];

    /** Archivos sin los que la versión no puede arrancar. */
    private const REQUIRED_FILES = ['artisan', 'composer.json', 'VERSION', 'vendor/autoload.php', 'public/index.php'];

    /**
     * @return array<string, mixed> El manifiesto escrito en manifest.json.
     */
    public function handle(
        string $sourceDir,
        string $outputDir,
        string $version,
        string $secretKey,
        int $partSize,
        ?string $previousVersion = null,
        ?string $commit = null,
        int $build = 1,
    ): array {
        if (! BumpVersion::isValid($version) || ($previousVersion !== null && ! BumpVersion::isValid($previousVersion))) {
            throw new InvalidArgumentException('Las versiones deben tener el formato MAYOR.MENOR.PARCHE.');
        }

        if ($build < 1) {
            throw new InvalidArgumentException('El número de build debe ser un entero mayor o igual que 1.');
        }

        if ($partSize < 1024) {
            throw new InvalidArgumentException('Cada parte debe medir al menos 1024 bytes.');
        }

        $source = $this->validatedSource($sourceDir, $version);
        $secret = $this->decodedSecretKey($secretKey);
        $releaseDir = rtrim($outputDir, '/\\').DIRECTORY_SEPARATOR."v{$version}";

        if (file_exists($releaseDir)) {
            throw new RuntimeException("Ya existe {$releaseDir}: una versión publicada no se sobrescribe. Bórrala a mano si de verdad quieres rehacerla.");
        }

        File::ensureDirectoryExists($releaseDir);

        try {
            return $this->build($source, $releaseDir, $version, $secret, $partSize, $previousVersion, $commit, $build);
        } catch (Throwable $exception) {
            File::deleteDirectory($releaseDir);

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function build(string $source, string $releaseDir, string $version, string $secret, int $partSize, ?string $previousVersion, ?string $commit, int $build): array
    {
        $files = $this->collectFiles($source);
        $zipName = "app-v{$version}.zip";
        $zipPath = $releaseDir.DIRECTORY_SEPARATOR.$zipName;

        $this->writeZip($zipPath, $files, $version);

        $package = ['file' => $zipName, 'size' => filesize($zipPath), 'sha256' => hash_file('sha256', $zipPath), 'files' => count($files)];
        $parts = $this->splitIntoParts($zipPath, $zipName, $releaseDir, $partSize);

        unlink($zipPath);

        $manifest = [
            'format' => self::FORMAT,
            'version' => $version,
            'build' => $build,
            'previous_version' => $previousVersion,
            'requires_php' => $this->requiredPhp($source),
            'built_at' => gmdate('c'),
            'commit' => $commit,
            'signing_key' => SigningKeyFile::fingerprint(base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))),
            'migrations' => $this->migrationNames($source),
            'package' => $package,
            'parts' => $parts,
        ];

        $manifestBytes = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        file_put_contents($releaseDir.DIRECTORY_SEPARATOR.'manifest.json', $manifestBytes);
        file_put_contents(
            $releaseDir.DIRECTORY_SEPARATOR.'manifest.json.sig',
            base64_encode(sodium_crypto_sign_detached($manifestBytes, $secret))."\n",
        );

        return $manifest;
    }

    private function validatedSource(string $sourceDir, string $version): string
    {
        foreach (['sodium', 'zip'] as $extension) {
            if (! extension_loaded($extension)) {
                throw new RuntimeException("Falta la extensión PHP «{$extension}» necesaria para empaquetar.");
            }
        }

        $source = realpath($sourceDir);

        if ($source === false || ! is_dir($source)) {
            throw new InvalidArgumentException("La carpeta de origen «{$sourceDir}» no existe.");
        }

        foreach (self::REQUIRED_FILES as $required) {
            if (! is_file($source.DIRECTORY_SEPARATOR.$required)) {
                throw new RuntimeException("Al origen le falta {$required}: no es una versión completa (¿se instaló vendor/ sin --dev?).");
            }
        }

        $declared = trim((string) file_get_contents($source.DIRECTORY_SEPARATOR.'VERSION'));

        if ($declared !== $version) {
            throw new RuntimeException("El archivo VERSION del origen dice «{$declared}», no «{$version}».");
        }

        return $source;
    }

    private function decodedSecretKey(string $secretKey): string
    {
        $secret = base64_decode(trim($secretKey), true);

        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException('La clave privada no es válida (se espera una clave Ed25519 en Base64; créala con php artisan release:keygen).');
        }

        return $secret;
    }

    /**
     * @return array<string, string> Ruta relativa (con /) => ruta absoluta, ordenado por ruta.
     */
    private function collectFiles(string $source): array
    {
        $directory = new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($directory, function (SplFileInfo $item) use ($source): bool {
            if ($item->isLink()) {
                return false;
            }

            return ! $this->isExcluded($this->relativePath($source, $item->getPathname()), $item->isDir());
        });

        $files = [];

        foreach (new RecursiveIteratorIterator($filter) as $item) {
            if ($item->isFile()) {
                $files[$this->relativePath($source, $item->getPathname())] = $item->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    private function relativePath(string $source, string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen($source) + 1));
    }

    private function isExcluded(string $relative, bool $isDirectory): bool
    {
        $segments = explode('/', $relative);
        $name = end($segments);

        if (in_array($segments[0], self::EXCLUDED_ROOT_DIRECTORIES, true) || in_array('.git', $segments, true)) {
            return true;
        }

        if ($isDirectory) {
            return false;
        }

        if ($segments[0] === 'bootstrap' && ($segments[1] ?? null) === 'cache' && $name !== '.gitignore') {
            return true;
        }

        return (str_starts_with($name, '.env') && $name !== '.env.example')
            || str_ends_with($name, 'release-private.key')
            || str_ends_with($name, '.log')
            || in_array($name, ['.DS_Store', 'Thumbs.db', '.phpunit.result.cache'], true);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function writeZip(string $zipPath, array $files, string $version): void
    {
        $hashes = array_map(fn (string $path): string => hash_file('sha256', $path), $files);
        $inventory = json_encode(['format' => self::FORMAT, 'version' => $version, 'files' => $hashes], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException("No se pudo crear {$zipPath}.");
        }

        $zip->addFromString('RELEASE.json', $inventory);

        foreach ($files as $relative => $absolute) {
            $zip->addFile($absolute, $relative);
        }

        if (! $zip->close()) {
            throw new RuntimeException('No se pudo terminar el ZIP (¿falta espacio en disco?).');
        }
    }

    /**
     * @return list<array{file: string, size: int, sha256: string}>
     */
    private function splitIntoParts(string $zipPath, string $zipName, string $releaseDir, int $partSize): array
    {
        $input = fopen($zipPath, 'rb');
        $parts = [];

        while (! feof($input)) {
            $name = sprintf('%s.%03d', $zipName, count($parts) + 1);
            $path = $releaseDir.DIRECTORY_SEPARATOR.$name;
            $output = fopen($path, 'wb');
            $hash = hash_init('sha256');
            $written = 0;

            while ($written < $partSize && ! feof($input)) {
                $chunk = fread($input, min(1048576, $partSize - $written));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                fwrite($output, $chunk);
                hash_update($hash, $chunk);
                $written += strlen($chunk);
            }

            fclose($output);

            if ($written === 0) {
                unlink($path);

                break;
            }

            $parts[] = ['file' => $name, 'size' => $written, 'sha256' => hash_final($hash)];
        }

        fclose($input);

        return $parts;
    }

    private function requiredPhp(string $source): ?string
    {
        $composer = json_decode((string) file_get_contents($source.DIRECTORY_SEPARATOR.'composer.json'), true);

        return $composer['require']['php'] ?? null;
    }

    /**
     * @return list<string>
     */
    private function migrationNames(string $source): array
    {
        $names = array_map('basename', glob($source.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'*.php') ?: []);
        sort($names);

        return $names;
    }
}
