<?php

use App\Actions\BuildReleasePackage;
use App\Actions\BumpVersion;
use App\Actions\SigningKeyFile;
use App\Actions\VerifyReleasePackage;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'proy03-release-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->tmp);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

/**
 * @return array{secret: string, public: string}
 */
function releaseKeys(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [
        'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
    ];
}

/**
 * Árbol de origen mínimo, con archivos que SÍ deben viajar y otros que NUNCA deben viajar.
 */
function releaseSource(string $tmp, string $version = '1.2.0'): string
{
    $source = $tmp.DIRECTORY_SEPARATOR.'source';

    $files = [
        'artisan' => '#!/usr/bin/env php',
        'composer.json' => '{"require":{"php":"^8.3"}}',
        'VERSION' => $version."\n",
        'vendor/autoload.php' => '<?php // autoload',
        'public/index.php' => '<?php // front controller',
        'public/build/manifest.json' => '{}',
        'app/Datos.bin' => random_bytes(6000),
        'database/migrations/2026_01_01_000000_crear_algo.php' => '<?php // migración',
        'bootstrap/cache/.gitignore' => "*\n!.gitignore\n",
        '.env.example' => 'APP_NAME=Laravel',
        // Lo que nunca debe viajar:
        '.env' => 'APP_KEY=secreto',
        '.env.production' => 'APP_KEY=secreto',
        'storage/logs/laravel.log' => 'datos del servidor',
        'storage/app/public/foto.jpg' => 'datos del servidor',
        'tests/ExampleTest.php' => '<?php // prueba',
        'node_modules/paquete/index.js' => '// dependencia de desarrollo',
        '.git/config' => '[core]',
        'bootstrap/cache/config.php' => '<?php // caché con rutas locales',
        'vendor/paquete/.git/HEAD' => 'ref',
    ];

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname($source.'/'.$path));
        file_put_contents($source.'/'.$path, $contents);
    }

    return $source;
}

/**
 * Construye una versión de prueba con partes pequeñas (2 KiB) para que se parta en varias.
 *
 * @return array{directory: string, manifest: array<string, mixed>, keys: array{secret: string, public: string}}
 */
function builtRelease(string $tmp, string $version = '1.2.0'): array
{
    $keys = releaseKeys();
    $manifest = app(BuildReleasePackage::class)->handle(releaseSource($tmp, $version), $tmp.'/releases', $version, $keys['secret'], 2048, previousVersion: '1.1.0', commit: 'abc1234');

    return ['directory' => $tmp.'/releases/v'.$version, 'manifest' => $manifest, 'keys' => $keys];
}

/**
 * @param  array<string, mixed>  $manifest
 * @return list<string> Nombres de los archivos dentro del ZIP que resulta de unir las partes.
 */
function releaseEntries(string $directory, array $manifest): array
{
    $zipPath = $directory.'/unido.zip';
    $output = fopen($zipPath, 'wb');

    foreach ($manifest['parts'] as $part) {
        fwrite($output, file_get_contents($directory.'/'.$part['file']));
    }

    fclose($output);

    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::RDONLY);
    $names = array_map(fn (int $index): string => $zip->getNameIndex($index), range(0, $zip->numFiles - 1));
    $zip->close();
    unlink($zipPath);

    return $names;
}

/**
 * Fabrica a mano un paquete bien firmado pero con un contenido que no debería aceptarse.
 *
 * @param  array{secret: string, public: string}  $keys
 * @param  array<string, string>  $entries  Archivos que lleva el ZIP.
 * @param  array<string, string>|null  $listed  Lo que dice RELEASE.json (por omisión, el SHA-256 real de $entries).
 */
function craftedRelease(string $tmp, array $keys, array $entries, ?array $listed = null): string
{
    $directory = $tmp.'/crafted/v9.9.9';
    File::ensureDirectoryExists($directory);

    $zipPath = $directory.'/app-v9.9.9.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('RELEASE.json', json_encode(['format' => 1, 'version' => '9.9.9', 'files' => $listed ?? array_map(fn (string $contents): string => hash('sha256', $contents), $entries)]));

    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();
    rename($zipPath, $zipPath.'.001');

    $manifest = json_encode([
        'format' => 1,
        'version' => '9.9.9',
        'package' => ['file' => 'app-v9.9.9.zip', 'size' => filesize($zipPath.'.001'), 'sha256' => hash_file('sha256', $zipPath.'.001'), 'files' => count($entries)],
        'parts' => [['file' => 'app-v9.9.9.zip.001', 'size' => filesize($zipPath.'.001'), 'sha256' => hash_file('sha256', $zipPath.'.001')]],
    ], JSON_PRETTY_PRINT);

    file_put_contents($directory.'/manifest.json', $manifest);
    file_put_contents($directory.'/manifest.json.sig', base64_encode(sodium_crypto_sign_detached($manifest, base64_decode($keys['secret']))));

    return $directory;
}

describe('versiones', function () {
    it('calcula la siguiente versión', function (string $current, string $type, string $expected) {
        expect(app(BumpVersion::class)->handle($current, $type))->toBe($expected);
    })->with([
        ['1.4.2', 'patch', '1.4.3'],
        ['1.4.2', 'minor', '1.5.0'],
        ['1.4.2', 'major', '2.0.0'],
        ['0.0.9', 'patch', '0.0.10'],
    ]);

    it('rechaza versiones y tipos inválidos', function () {
        expect(fn () => app(BumpVersion::class)->handle('1.4', 'patch'))->toThrow(InvalidArgumentException::class, 'MAYOR.MENOR.PARCHE')
            ->and(fn () => app(BumpVersion::class)->handle('1.4.2', 'enorme'))->toThrow(InvalidArgumentException::class, 'desconocido')
            ->and(BumpVersion::isValid('01.2.3'))->toBeFalse()
            ->and(BumpVersion::isValid('1.2.3-beta'))->toBeFalse();
    });
});

describe('empaquetado', function () {
    it('parte el ZIP, firma el manifiesto y no deja el ZIP completo', function () {
        ['directory' => $directory, 'manifest' => $manifest] = builtRelease($this->tmp);

        expect($manifest['parts'])->not->toHaveCount(1)
            ->and($manifest['version'])->toBe('1.2.0')
            ->and($manifest['previous_version'])->toBe('1.1.0')
            ->and($manifest['commit'])->toBe('abc1234')
            ->and($manifest['build'])->toBe(1)
            ->and($manifest['requires_php'])->toBe('^8.3')
            ->and($manifest['migrations'])->toBe(['2026_01_01_000000_crear_algo.php'])
            ->and(array_sum(array_column($manifest['parts'], 'size')))->toBe($manifest['package']['size'])
            ->and(max(array_column($manifest['parts'], 'size')))->toBeLessThanOrEqual(2048)
            ->and(is_file($directory.'/app-v1.2.0.zip'))->toBeFalse()
            ->and(is_file($directory.'/manifest.json'))->toBeTrue()
            ->and(is_file($directory.'/manifest.json.sig'))->toBeTrue();

        foreach ($manifest['parts'] as $part) {
            expect(filesize($directory.'/'.$part['file']))->toBe($part['size']);
        }
    });

    it('registra el número de build y lo exige mayor o igual que 1', function () {
        $keys = releaseKeys();
        $source = releaseSource($this->tmp, '1.2.0');
        $builder = app(BuildReleasePackage::class);

        $manifest = $builder->handle($source, $this->tmp.'/releases', '1.2.0', $keys['secret'], 2048, build: 3);

        expect($manifest['build'])->toBe(3)
            ->and(json_decode(file_get_contents($this->tmp.'/releases/v1.2.0/manifest.json'), true)['build'])->toBe(3)
            ->and(fn () => $builder->handle($source, $this->tmp.'/otra-salida', '1.2.0', $keys['secret'], 2048, build: 0))
            ->toThrow(InvalidArgumentException::class, 'build');
    });

    it('lleva el código y la compilación, pero nunca secretos, datos del servidor ni caché', function () {
        ['directory' => $directory, 'manifest' => $manifest] = builtRelease($this->tmp);

        $entries = releaseEntries($directory, $manifest);

        expect($entries)->toContain('RELEASE.json', 'artisan', 'VERSION', 'vendor/autoload.php', 'public/build/manifest.json', '.env.example', 'bootstrap/cache/.gitignore')
            ->and($entries)->not->toContain('.env', '.env.production', 'storage/logs/laravel.log', 'storage/app/public/foto.jpg', 'tests/ExampleTest.php', 'node_modules/paquete/index.js', '.git/config', 'bootstrap/cache/config.php', 'vendor/paquete/.git/HEAD');
    });

    it('no sobrescribe una versión ya publicada', function () {
        builtRelease($this->tmp);

        expect(fn () => builtRelease($this->tmp))->toThrow(RuntimeException::class, 'no se sobrescribe');
    });

    it('rechaza un origen incompleto, de otra versión o una clave que no es válida, sin dejar carpeta a medias', function () {
        $keys = releaseKeys();
        $source = releaseSource($this->tmp);
        $build = fn (string $version = '1.2.0', ?string $key = null) => app(BuildReleasePackage::class)->handle($source, $this->tmp.'/out', $version, $key ?? $keys['secret'], 2048);

        expect(fn () => $build('1.3.0'))->toThrow(RuntimeException::class, 'dice «1.2.0», no «1.3.0»')
            ->and(fn () => $build('1.2.0', 'no-es-una-clave'))->toThrow(InvalidArgumentException::class, 'clave privada')
            ->and(fn () => $build('1.2'))->toThrow(InvalidArgumentException::class, 'MAYOR.MENOR.PARCHE');

        unlink($source.'/vendor/autoload.php');

        expect(fn () => $build())->toThrow(RuntimeException::class, 'vendor/autoload.php')
            ->and(is_dir($this->tmp.'/out'))->toBeFalse();
    });
});

describe('verificación', function () {
    it('acepta un paquete íntegro y firmado', function () {
        ['directory' => $directory, 'keys' => $keys] = builtRelease($this->tmp);

        expect(app(VerifyReleasePackage::class)->handle($directory, $keys['public'])['version'])->toBe('1.2.0')
            ->and(app(VerifyReleasePackage::class)->handle($directory, $keys['public'], deep: false)['version'])->toBe('1.2.0');
    });

    it('rechaza una firma hecha con otra clave o sin clave pública', function () {
        ['directory' => $directory] = builtRelease($this->tmp);

        expect(fn () => app(VerifyReleasePackage::class)->handle($directory, releaseKeys()['public']))->toThrow(RuntimeException::class, 'firma')
            ->and(fn () => app(VerifyReleasePackage::class)->handle($directory, null))->toThrow(RuntimeException::class, 'clave pública');
    });

    it('rechaza un manifiesto modificado aunque las partes sean las originales', function () {
        ['directory' => $directory, 'keys' => $keys] = builtRelease($this->tmp);

        file_put_contents($directory.'/manifest.json', str_replace('"version": "1.2.0"', '"version": "1.2.1"', file_get_contents($directory.'/manifest.json')));

        expect(fn () => app(VerifyReleasePackage::class)->handle($directory, $keys['public']))->toThrow(RuntimeException::class, 'firma');
    });

    it('rechaza una parte alterada, truncada o ausente', function () {
        ['directory' => $directory, 'manifest' => $manifest, 'keys' => $keys] = builtRelease($this->tmp);
        $verify = fn () => app(VerifyReleasePackage::class)->handle($directory, $keys['public']);
        $first = $directory.'/'.$manifest['parts'][0]['file'];
        $original = file_get_contents($first);

        file_put_contents($first, chr(ord($original[0]) ^ 1).substr($original, 1));
        expect($verify)->toThrow(RuntimeException::class, 'dañada');

        file_put_contents($first, substr($original, 0, -1));
        expect($verify)->toThrow(RuntimeException::class, 'tamaño');

        unlink($first);
        expect($verify)->toThrow(RuntimeException::class, 'Falta la parte');
    });

    it('rechaza rutas que se salen de la carpeta de destino', function (string $name) {
        $keys = releaseKeys();
        $directory = craftedRelease($this->tmp, $keys, [$name => '<?php // malicioso']);

        expect(fn () => app(VerifyReleasePackage::class)->handle($directory, $keys['public']))->toThrow(RuntimeException::class, 'ruta peligrosa');
    })->with(['../fuera.php', 'app/../../fuera.php', '/etc/passwd', 'C:/Windows/x.php', 'app\\..\\x.php']);

    it('rechaza archivos que no figuran en RELEASE.json, que faltan o que no coinciden con su SHA-256', function () {
        $keys = releaseKeys();
        $verify = fn (string $directory) => fn () => app(VerifyReleasePackage::class)->handle($directory, $keys['public']);

        $extra = craftedRelease($this->tmp, $keys, ['app/a.php' => 'a', 'app/b.php' => 'b'], ['app/a.php' => hash('sha256', 'a')]);
        expect($verify($extra))->toThrow(RuntimeException::class, 'no figura en RELEASE.json');

        File::deleteDirectory($this->tmp.'/crafted');

        $altered = craftedRelease($this->tmp, $keys, ['app/a.php' => 'distinto'], ['app/a.php' => hash('sha256', 'original')]);
        expect($verify($altered))->toThrow(RuntimeException::class, 'alterado');

        File::deleteDirectory($this->tmp.'/crafted');

        $missing = craftedRelease($this->tmp, $keys, ['app/a.php' => 'a'], ['app/a.php' => hash('sha256', 'a'), 'app/falta.php' => hash('sha256', 'x')]);
        expect($verify($missing))->toThrow(RuntimeException::class, 'Faltan en el ZIP');
    });
});

describe('comandos', function () {
    it('release:next calcula la versión y solo con --write la guarda', function () {
        file_put_contents($this->tmp.'/VERSION', "1.4.2\n");
        $this->app->setBasePath($this->tmp);

        $this->artisan('release:next', ['type' => 'minor'])->expectsOutput('1.5.0')->assertSuccessful();
        expect(trim(file_get_contents($this->tmp.'/VERSION')))->toBe('1.4.2');

        $this->artisan('release:next', ['type' => 'patch', '--write' => true])->expectsOutput('1.4.3')->assertSuccessful();
        expect(trim(file_get_contents($this->tmp.'/VERSION')))->toBe('1.4.3');

        $this->artisan('release:next', ['type' => 'enorme'])->assertFailed();
    });

    it('release:package y release:verify funcionan de punta a punta y detectan una alteración', function () {
        $keys = releaseKeys();
        $keyFile = $this->tmp.'/firma.key';
        file_put_contents($keyFile, $keys['secret']."\n");
        config(['release.public_key' => $keys['public'], 'release.signing_key_file' => $keyFile]);
        $output = $this->tmp.'/salida';

        $this->artisan('release:package', ['version' => '1.2.0', '--source' => releaseSource($this->tmp), '--output' => $output, '--previous' => '1.1.0'])
            ->expectsOutputToContain('empaquetada y verificada')
            ->assertSuccessful();

        $this->artisan('release:verify', ['directory' => $output.'/v1.2.0'])->expectsOutputToContain('firma válida')->assertSuccessful();
        $this->artisan('release:verify', ['directory' => $output.'/v1.2.0', '--quick' => true])->assertSuccessful();

        $part = $output.'/v1.2.0/app-v1.2.0.zip.001';
        file_put_contents($part, 'x'.substr(file_get_contents($part), 1));

        $this->artisan('release:verify', ['directory' => $output.'/v1.2.0'])->expectsOutputToContain('dañada')->assertFailed();
    });

    it('release:package firma con una clave protegida con contraseña y falla si la contraseña es incorrecta', function () {
        $pair = app(SigningKeyFile::class)->handle($this->tmp.'/claves', 'una-contraseña-bien-larga', null, null, 'interactive');
        config(['release.public_key' => trim(file_get_contents($pair['public'])), 'release.signing_key_file' => $pair['private'], 'release.key_passphrase' => 'otra-contraseña-larga']);
        $arguments = ['version' => '1.2.0', '--source' => releaseSource($this->tmp), '--output' => $this->tmp.'/salida'];

        $this->artisan('release:package', $arguments)->expectsOutputToContain('Contraseña incorrecta')->assertFailed();
        expect(is_dir($this->tmp.'/salida'))->toBeFalse();

        config(['release.key_passphrase' => 'una-contraseña-bien-larga']);

        $this->artisan('release:package', $arguments)
            ->expectsOutputToContain('Huella de la clave')
            ->assertSuccessful();
    });

    it('release:package avisa si falta la clave privada', function () {
        config(['release.signing_key_file' => null]);

        $this->artisan('release:package', ['version' => '1.2.0', '--source' => releaseSource($this->tmp), '--output' => $this->tmp.'/salida'])
            ->expectsOutputToContain('No se encontró la clave privada')
            ->assertFailed();
    });
});
