<?php

use App\Actions\BuildReleasePackage;
use App\Actions\PrepareFirstInstall;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Preparación de la primera instalación en un hosting sin SSH: sitio verificado y descomprimido, inventario, plantilla
 * del .env, base de datos inicial (construida en una base temporal) e instrucciones.
 */
beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'proy03-firstinstall-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->tmp);

    $pair = sodium_crypto_sign_keypair();
    $secret = base64_encode(sodium_crypto_sign_secretkey($pair));
    config(['release.public_key' => base64_encode(sodium_crypto_sign_publickey($pair)), 'release.extra_public_keys' => []]);

    $source = $this->tmp.'/source';
    $files = [
        'artisan' => '#!/usr/bin/env php',
        'composer.json' => '{"require":{"php":"^8.3"}}',
        'VERSION' => "1.2.0\n",
        'vendor/autoload.php' => '<?php // autoload',
        'public/index.php' => '<?php // front controller',
        'public/build/manifest.json' => '{}',
        'app/Datos.bin' => random_bytes(5000),
        'storage/framework/views/.gitignore' => "*\n!.gitignore\n",
        'storage/app/private/.gitignore' => "*\n!.gitignore\n",
        'bootstrap/cache/.gitignore' => "*\n!.gitignore\n",
        '.env.example' => "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nAPP_URL=http://localhost:8000\n\nLOG_LEVEL=debug\n\nDB_CONNECTION=sqlite\n# DB_HOST=127.0.0.1\n# DB_PORT=3306\n# DB_DATABASE=laravel\n# DB_USERNAME=root\n# DB_PASSWORD=\n\nSESSION_ENCRYPT=true\nRELEASE_SIGNING_KEY_FILE=C:\\claves\\privada.key\nHEALTH_TOKEN=\n",
    ];

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname($source.'/'.$path));
        file_put_contents($source.'/'.$path, $contents);
    }

    app(BuildReleasePackage::class)->handle($source, $this->tmp.'/releases', '1.2.0', $secret, 2048, build: 2);

    $this->package = $this->tmp.'/releases/v1.2.0';
    $this->output = $this->tmp.'/releases/instalar-v1.2.0';
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

describe('PrepareFirstInstall', function () {
    it('descomprime el sitio verificado, anota su inventario y prepara la plantilla del .env y las instrucciones', function () {
        $result = app(PrepareFirstInstall::class)->handle($this->package, $this->output, ['database' => false]);
        $site = $this->output.'/sitio';
        $installed = json_decode(file_get_contents($site.'/storage/app/private/updates/installed.json'), true);
        $env = file_get_contents($this->output.'/env-produccion.txt');

        expect($result['version'])->toBe('1.2.0')
            ->and($result['build'])->toBe(2)
            ->and($result['admin_password'])->toBeNull()
            ->and($result['database'])->toBeNull()
            ->and(is_file($site.'/artisan'))->toBeTrue()
            ->and(is_file($site.'/vendor/autoload.php'))->toBeTrue()
            ->and(is_file($site.'/public/build/manifest.json'))->toBeTrue()
            ->and(is_file($site.'/storage/framework/views/.gitignore'))->toBeTrue()
            ->and(is_file($site.'/bootstrap/cache/.gitignore'))->toBeTrue()
            ->and(is_file($site.'/RELEASE.json'))->toBeFalse()
            ->and(is_file($site.'/storage/app/private/updates/.htaccess'))->toBeTrue()
            ->and($installed['version'])->toBe('1.2.0')
            ->and($installed['build'])->toBe(2)
            ->and($installed['files'])->toHaveKey('artisan')
            ->and($installed['files']['artisan'])->toBe(hash('sha256', '#!/usr/bin/env php'))
            ->and($installed['files'])->not->toHaveKey('RELEASE.json')
            ->and(is_dir($this->output.'/base-de-datos'))->toBeFalse()
            ->and(file_get_contents($this->output.'/LEEME.txt'))->toContain('versión 1.2.0 (build 2)')->toContain('--no-database');

        expect($env)->toContain("APP_ENV=production\n")
            ->toContain("APP_DEBUG=false\n")
            ->toContain("DB_CONNECTION=mysql\n")
            ->toContain("DB_HOST=CAMBIAR\n")
            ->toContain("DB_PASSWORD=CAMBIAR\n")
            ->toContain("SESSION_SECURE_COOKIE=true\n")
            ->toContain("RELEASE_SIGNING_KEY_FILE=\n")
            ->not->toContain('C:\\claves')
            ->not->toContain('# DB_HOST')
            ->and($env)->toMatch('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m')
            ->and($env)->toMatch('/^HEALTH_TOKEN=[0-9a-f]{48}$/m');
    });

    it('arma la base de datos inicial en una base temporal sin tocar la de desarrollo', function () {
        $before = DB::table('users')->count();
        $default = config('database.default');

        $result = app(PrepareFirstInstall::class)->handle($this->package, $this->output, ['admin_email' => 'admin@tienda.example', 'admin_name' => 'Ana Admin']);

        $sql = file_get_contents($result['database_sql']);

        expect($result['admin_password'])->toHaveLength(20)
            ->and(is_file($result['database']))->toBeTrue()
            ->and(gzdecode(file_get_contents($result['database'])))->toBe($sql)
            ->and($sql)->toContain('CREATE TABLE "users"')
            ->and($sql)->toContain('admin@tienda.example')
            ->and($sql)->toContain('Ana Admin')
            ->and($sql)->toContain("'updates.manage'")
            ->and($sql)->toContain("'admin'")
            ->and($sql)->toContain("'cliente'")
            ->and($sql)->toMatch('/\$(argon2id|2y)\$/')
            ->and($sql)->not->toContain($result['admin_password'])
            ->and($sql)->not->toContain('client@example.com')
            ->and($sql)->not->toContain('Test User')
            ->and($sql)->not->toContain('INSERT INTO "products"')
            ->and(config('database.default'))->toBe($default)
            ->and(DB::table('users')->count())->toBe($before)
            ->and(DB::table('users')->where('email', 'admin@tienda.example')->exists())->toBeFalse()
            ->and(file_get_contents($this->output.'/LEEME.txt'))->toContain('phpMyAdmin');
    });

    it('incluye las clasificaciones de ejemplo solo si se piden', function () {
        $without = app(PrepareFirstInstall::class)->handle($this->package, $this->output, ['admin_email' => 'a@b.example']);
        expect(file_get_contents($without['database_sql']))->not->toContain('Electrónica');

        File::deleteDirectory($this->output);

        $with = app(PrepareFirstInstall::class)->handle($this->package, $this->output, ['admin_email' => 'a@b.example', 'classifications' => true]);
        expect(file_get_contents($with['database_sql']))->toContain('Electrónica');
    });

    it('no sobrescribe, exige un correo válido y no deja nada a medias si el paquete está alterado', function () {
        $prepare = app(PrepareFirstInstall::class);

        expect(fn () => $prepare->handle($this->package, $this->output, ['admin_email' => 'no-es-correo']))->toThrow(InvalidArgumentException::class, 'correo del administrador')
            ->and(fn () => $prepare->handle($this->package, $this->output, []))->toThrow(InvalidArgumentException::class, 'correo del administrador')
            ->and(is_dir($this->output))->toBeFalse();

        File::ensureDirectoryExists($this->output);
        file_put_contents($this->output.'/algo.txt', 'ya estaba');

        expect(fn () => $prepare->handle($this->package, $this->output, ['database' => false]))->toThrow(RuntimeException::class, 'no se sobrescribe')
            ->and(file_get_contents($this->output.'/algo.txt'))->toBe('ya estaba');

        File::deleteDirectory($this->output);
        $part = $this->package.'/app-v1.2.0.zip.001';
        file_put_contents($part, 'x'.substr(file_get_contents($part), 1));

        expect(fn () => $prepare->handle($this->package, $this->output, ['database' => false]))->toThrow(RuntimeException::class, 'dañada')
            ->and(is_dir($this->output))->toBeFalse();
    });
});

describe('release:first-install', function () {
    it('prepara la carpeta y muestra la contraseña del administrador una sola vez', function () {
        $this->artisan('release:first-install', ['package' => $this->package, '--output' => $this->output, '--admin-email' => 'admin@tienda.example'])
            ->expectsOutputToContain('Primera instalación de la versión 1.2.0 (build 2) preparada')
            ->expectsOutputToContain('Contraseña inicial del administrador')
            ->expectsOutputToContain('env-produccion.txt contiene secretos')
            ->assertSuccessful();

        expect(is_file($this->output.'/sitio/artisan'))->toBeTrue()
            ->and(is_file($this->output.'/base-de-datos/base-de-datos-inicial.sql.gz'))->toBeTrue();

        $this->artisan('release:first-install', ['package' => $this->package, '--output' => $this->output, '--no-database' => true])
            ->expectsOutputToContain('no se sobrescribe')
            ->assertFailed();
    });

    it('con --no-database no pide correo y avisa de lo que falta si no es válido', function () {
        $this->artisan('release:first-install', ['package' => $this->package, '--output' => $this->output, '--no-database' => true])
            ->expectsOutputToContain('no preparada')
            ->assertSuccessful();

        File::deleteDirectory($this->output);

        $this->artisan('release:first-install', ['package' => $this->package, '--output' => $this->output, '--admin-email' => 'mal'])
            ->expectsOutputToContain('correo del administrador')
            ->assertFailed();
    });
});
