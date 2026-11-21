<?php

use App\Actions\BuildReleasePackage;
use App\Actions\InspectUpdate;
use App\Actions\RunUpdate;
use App\Actions\StoreUpdatePart;
use App\Actions\UpdateHooks;
use App\Actions\UpdateStorage;
use App\RoleName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/**
 * Instalador de actualizaciones: se prueba contra una «aplicación» en una carpeta temporal (no contra el proyecto)
 * y con un UpdateHooks falso que anota qué se le pidió, sin tocar mantenimiento ni base de datos.
 */
beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'proy03-update-'.bin2hex(random_bytes(4));
    $this->root = $this->tmp.'/app';
    $this->updates = $this->tmp.'/updates';
    File::ensureDirectoryExists($this->root);

    $pair = sodium_crypto_sign_keypair();
    $this->secret = base64_encode(sodium_crypto_sign_secretkey($pair));
    $this->public = base64_encode(sodium_crypto_sign_publickey($pair));

    config(['release.public_key' => $this->public, 'release.extra_public_keys' => [], 'release.step_seconds' => 30, 'release.upload_max_kb' => 10]);

    app()->instance(UpdateStorage::class, new UpdateStorage($this->root, $this->updates));
    $this->hooks = new FakeUpdateHooks;
    app()->instance(UpdateHooks::class, $this->hooks);

    installedApp($this->root, $this->updates);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

class FakeUpdateHooks extends UpdateHooks
{
    /** @var list<string> */
    public array $calls = [];

    public bool $failMigration = false;

    public function maintenanceOn(): void
    {
        $this->calls[] = 'down';
    }

    public function maintenanceOff(): void
    {
        $this->calls[] = 'up';
    }

    public function backupDatabase(string $label): array
    {
        $this->calls[] = "backup:{$label}";

        return ['file' => '/respaldos/'.$label.'.sql.gz'];
    }

    public function restoreDatabase(string $file): void
    {
        $this->calls[] = 'restore:'.basename($file);
    }

    public function migrate(): string
    {
        $this->calls[] = 'migrate';

        if ($this->failMigration) {
            throw new RuntimeException('La migración 2026_11_21_000000 falló.');
        }

        return 'Nothing to migrate.';
    }

    public function clearCaches(): void
    {
        $this->calls[] = 'clear';
    }
}

/**
 * La aplicación «instalada»: versión 1.0.0, con su inventario en installed.json, un archivo que la 1.1.0 ya no trae
 * y datos del servidor (.env, storage, caché) que una actualización nunca debe tocar.
 *
 * @return array<string, string> Contenido de cada archivo de la versión instalada.
 */
function installedApp(string $root, string $updates): array
{
    $files = [
        'VERSION' => "1.0.0\n",
        'artisan' => 'artisan viejo',
        'composer.json' => '{"require":{"php":"^8.3"}}',
        'app/Viejo.php' => '<?php // clase vieja',
        'app/Comun.php' => '<?php // común, versión 1',
        'vendor/autoload.php' => '<?php // autoload viejo',
        'config/app.php' => '<?php // config vieja',
        'public/index.php' => '<?php // index viejo',
        'public/build/manifest.json' => '{"viejo":true}',
        'bootstrap/app.php' => '<?php // bootstrap viejo',
        'sobra/archivo.txt' => 'ya no viaja en la 1.1.0',
    ];

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname($root.'/'.$path));
        file_put_contents($root.'/'.$path, $contents);
    }

    foreach (['.env' => 'APP_KEY=secreto', 'storage/app/dato.txt' => 'dato del servidor', 'bootstrap/cache/config.php' => '<?php // caché local', 'public/storage/enlace.txt' => 'no es del paquete'] as $path => $contents) {
        File::ensureDirectoryExists(dirname($root.'/'.$path));
        file_put_contents($root.'/'.$path, $contents);
    }

    File::ensureDirectoryExists($updates);
    file_put_contents($updates.'/installed.json', json_encode(['version' => '1.0.0', 'build' => 1, 'files' => array_map(fn (string $contents): string => hash('sha256', $contents), $files)]));

    return $files;
}

/**
 * Empaqueta la 1.1.0 (con partes de 2 KiB, para que haya varias) y la deja en inbox/, como si se hubiera subido.
 *
 * @param  array<string, string>  $overrides  Archivos de la versión que reemplazan a los de ejemplo (o se agregan).
 * @return array{directory: string, manifest: array<string, mixed>}
 */
function uploadedRelease(string $tmp, string $updates, string $secret, string $version = '1.1.0', array $overrides = [], int $build = 1): array
{
    $source = $tmp.'/source-'.$version.'-'.$build;
    File::deleteDirectory($source);

    $files = [
        'VERSION' => $version."\n",
        'artisan' => 'artisan nuevo',
        'composer.json' => '{"require":{"php":"^8.3"}}',
        'app/Comun.php' => '<?php // común, versión 2',
        'app/Nuevo.php' => '<?php // clase nueva',
        'app/Datos.bin' => random_bytes(5000),
        'vendor/autoload.php' => '<?php // autoload nuevo',
        'config/app.php' => '<?php // config nueva',
        'public/index.php' => '<?php // index nuevo',
        'public/build/manifest.json' => '{"nuevo":true}',
        'bootstrap/app.php' => '<?php // bootstrap nuevo',
        ...$overrides,
    ];

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname($source.'/'.$path));
        file_put_contents($source.'/'.$path, $contents);
    }

    $manifest = app(BuildReleasePackage::class)->handle($source, $tmp.'/out-'.$version.'-'.$build, $version, $secret, 2048, previousVersion: '1.0.0', build: $build);
    $directory = $updates.'/inbox/v'.$version;
    File::deleteDirectory($directory);
    File::copyDirectory($tmp.'/out-'.$version.'-'.$build.'/v'.$version, $directory);

    return ['directory' => $directory, 'manifest' => $manifest];
}

/**
 * @return array<string, mixed> El último avance.
 */
function runToEnd(RunUpdate $runner): array
{
    for ($turn = 0; $turn < 60; $turn++) {
        $progress = $runner->step();

        if ($progress['finished']) {
            return $progress;
        }
    }

    throw new RuntimeException('La instalación no terminó en 60 pasos.');
}

/**
 * @return array<string, string> Ruta relativa => contenido, de todo lo que hay en la carpeta.
 */
function treeOf(string $root): array
{
    $tree = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $tree[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = (string) file_get_contents($file->getPathname());
    }

    ksort($tree);

    return $tree;
}

describe('unidades de reemplazo y restricción de PHP', function () {
    it('agrupa los archivos del paquete en carpetas o archivos que se reemplazan de una vez, y protege lo del servidor', function (string $path, ?string $unit) {
        expect(RunUpdate::unitOf($path))->toBe($unit);
    })->with([
        ['app/Models/User.php', 'app'],
        ['vendor/laravel/framework/src/x.php', 'vendor'],
        ['artisan', 'artisan'],
        ['VERSION', 'VERSION'],
        ['public/index.php', 'public/index.php'],
        ['public/build/assets/app.js', 'public/build'],
        ['bootstrap/app.php', 'bootstrap/app.php'],
        ['bootstrap/cache/.gitignore', null],
        ['storage/app/foto.jpg', null],
        ['.env', null],
        ['.env.production', null],
        ['.git/config', null],
    ]);

    it('evalúa las restricciones de PHP de composer.json', function (string $constraint, string $php, bool $expected) {
        expect(InspectUpdate::phpSatisfies($constraint, $php))->toBe($expected);
    })->with([
        ['^8.3', '8.4.1', true],
        ['^8.3', '8.2.9', false],
        ['^8.3', '9.0.0', false],
        ['>=8.2 <8.5', '8.4.0', true],
        ['>=8.2 <8.5', '8.5.0', false],
        ['~8.3.1', '8.3.0', false],
        ['~8.3.1', '8.3.9', true],
        ['8.3.*', '8.3.7', true],
        ['8.3.*', '8.4.0', false],
        ['^7.4 || ^8.1', '8.2.0', true],
        ['^0.2', '0.2.5', true],
        ['algo-raro', '8.4.0', true],
    ]);
});

describe('subida de partes', function () {
    it('guarda los archivos con nombre de paquete y rechaza cualquier otro', function () {
        $store = app(StoreUpdatePart::class);

        expect($store->handle(UploadedFile::fake()->create('manifest.json', 1), '1.1.0'))->toBe(['manifest.json'])
            ->and($store->handle(UploadedFile::fake()->create('app-v1.1.0.zip.001', 5), '1.1.0'))->toBe(['app-v1.1.0.zip.001', 'manifest.json'])
            ->and(is_file($this->updates.'/inbox/v1.1.0/app-v1.1.0.zip.001'))->toBeTrue();

        expect(file_get_contents($this->updates.'/.htaccess'))->toContain('Require all denied');

        foreach (['../evil.php', 'shell.php', 'app-v1.1.0.zip', 'app-v1.1.0.zip.1', 'manifest.json.bak'] as $name) {
            expect(fn () => $store->handle(UploadedFile::fake()->create($name, 1), '1.1.0'))->toThrow(InvalidArgumentException::class, 'no es un archivo de actualización');
        }

        expect(fn () => $store->handle(UploadedFile::fake()->create('app-v9.9.9.zip.001', 1), '1.1.0'))->toThrow(InvalidArgumentException::class, 'es de la versión 9.9.9')
            ->and(fn () => $store->handle(UploadedFile::fake()->create('manifest.json', 1), '1.1'))->toThrow(InvalidArgumentException::class, 'MAYOR.MENOR.PARCHE')
            ->and(fn () => $store->handle(UploadedFile::fake()->create('app-v1.1.0.zip.002', 11), '1.1.0'))->toThrow(InvalidArgumentException::class, 'demasiado grande')
            ->and(fn () => $store->handle(UploadedFile::fake()->create('manifest.json.sig', 2), '1.1.0'))->toThrow(InvalidArgumentException::class, 'demasiado grande');
    });
});

describe('InspectUpdate', function () {
    it('da por instalable un paquete íntegro, firmado y más nuevo que el instalado', function () {
        uploadedRelease($this->tmp, $this->updates, $this->secret);

        $report = app(InspectUpdate::class)->handle('1.1.0');

        expect($report['ok'])->toBeTrue()
            ->and($report['problems'])->toBe([])
            ->and($report['summary']['version'])->toBe('1.1.0')
            ->and($report['summary']['build'])->toBe(1)
            ->and($report['summary']['signing_key'])->toMatch('/^[0-9A-F]{4}(-[0-9A-F]{4}){3}$/')
            ->and($report['installed'])->toBe(['version' => '1.0.0', 'build' => 1])
            ->and($report['parts']['expected'])->toBeGreaterThan(1)
            ->and($report['parts']['present'])->toBe($report['parts']['expected']);
    });

    it('rechaza una versión anterior, la misma versión sin build nuevo y acepta un build mayor', function () {
        file_put_contents($this->root.'/VERSION', "2.0.0\n");
        uploadedRelease($this->tmp, $this->updates, $this->secret);

        expect(app(InspectUpdate::class)->handle('1.1.0')['problems'][0])->toContain('No se permite instalar una versión anterior');

        file_put_contents($this->root.'/VERSION', "1.1.0\n");
        file_put_contents($this->updates.'/installed.json', json_encode(['version' => '1.1.0', 'build' => 2, 'files' => []]));

        expect(app(InspectUpdate::class)->handle('1.1.0')['problems'][0])->toContain('ya está instalada');

        uploadedRelease($this->tmp, $this->updates, $this->secret, build: 3);

        expect(app(InspectUpdate::class)->handle('1.1.0')['ok'])->toBeTrue();
    });

    it('rechaza un paquete firmado con una clave desconocida, sin partes, alterado o que pide más PHP', function () {
        uploadedRelease($this->tmp, $this->updates, $this->secret);
        $inspect = app(InspectUpdate::class);

        config(['release.public_key' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]);
        expect($inspect->handle('1.1.0')['problems'][0])->toContain('La firma del manifiesto no es válida');

        config(['release.public_key' => $this->public]);
        unlink($this->updates.'/inbox/v1.1.0/app-v1.1.0.zip.002');
        expect($inspect->handle('1.1.0')['problems'][0])->toContain('Falta la parte');

        expect($inspect->handle('9.9.9')['problems'][0])->toContain('No hay archivos subidos');

        uploadedRelease($this->tmp, $this->updates, $this->secret, overrides: ['composer.json' => '{"require":{"php":"^99.0"}}'], build: 2);
        expect(implode(' ', $inspect->handle('1.1.0')['problems']))->toContain('necesita PHP ^99.0');
    });

    it('avisa si el paquete se salta versiones', function () {
        file_put_contents($this->root.'/VERSION', "0.9.0\n");
        uploadedRelease($this->tmp, $this->updates, $this->secret);

        $report = app(InspectUpdate::class)->handle('1.1.0');

        expect($report['ok'])->toBeTrue()->and($report['warnings'][0])->toContain('se saltan versiones intermedias');
    });
});

describe('RunUpdate', function () {
    it('instala la versión nueva: reemplaza el código, aparta lo anterior y no toca los datos del servidor', function () {
        uploadedRelease($this->tmp, $this->updates, $this->secret);
        $runner = app(RunUpdate::class);

        $runner->start('1.1.0', 7);
        $final = runToEnd($runner);

        expect($final['ok'])->toBeTrue()
            ->and($final['phase'])->toBe('done')
            ->and($final['percent'])->toBe(100)
            ->and($this->hooks->calls)->toBe(['down', 'backup:antes-de-v1.1.0', 'migrate', 'clear', 'up'])
            ->and(trim(file_get_contents($this->root.'/VERSION')))->toBe('1.1.0')
            ->and(file_get_contents($this->root.'/app/Comun.php'))->toBe('<?php // común, versión 2')
            ->and(is_file($this->root.'/app/Nuevo.php'))->toBeTrue()
            ->and(is_file($this->root.'/app/Viejo.php'))->toBeFalse()
            ->and(file_get_contents($this->root.'/vendor/autoload.php'))->toBe('<?php // autoload nuevo')
            ->and(file_get_contents($this->root.'/public/build/manifest.json'))->toBe('{"nuevo":true}')
            ->and(file_get_contents($this->root.'/public/index.php'))->toBe('<?php // index nuevo')
            ->and(file_get_contents($this->root.'/bootstrap/app.php'))->toBe('<?php // bootstrap nuevo')
            ->and(is_dir($this->root.'/sobra'))->toBeFalse()
            ->and(file_get_contents($this->root.'/.env'))->toBe('APP_KEY=secreto')
            ->and(file_get_contents($this->root.'/storage/app/dato.txt'))->toBe('dato del servidor')
            ->and(file_get_contents($this->root.'/bootstrap/cache/config.php'))->toBe('<?php // caché local')
            ->and(file_get_contents($this->root.'/public/storage/enlace.txt'))->toBe('no es del paquete')
            ->and(file_get_contents($this->updates.'/work/rollback/app/Viejo.php'))->toBe('<?php // clase vieja')
            ->and(file_get_contents($this->updates.'/work/rollback/sobra/archivo.txt'))->toBe('ya no viaja en la 1.1.0')
            ->and(is_dir($this->updates.'/work/staging'))->toBeFalse()
            ->and(is_file($this->updates.'/work/package.zip'))->toBeFalse()
            ->and($runner->isActive())->toBeFalse();

        $installed = json_decode(file_get_contents($this->updates.'/installed.json'), true);

        expect($installed['version'])->toBe('1.1.0')
            ->and($installed['build'])->toBe(1)
            ->and($installed['files'])->toHaveKey('app/Nuevo.php')
            ->and($installed['files'])->not->toHaveKey('sobra/archivo.txt')
            ->and(file_get_contents($this->updates.'/history.log'))->toContain('v1.1.0  build 1  desde v1.0.0  usuario 7  instalada');
    });

    it('instalar el mismo paquete con otro build es posible y la segunda instalación vuelve a empezar limpia', function () {
        $runner = app(RunUpdate::class);

        uploadedRelease($this->tmp, $this->updates, $this->secret);
        $runner->start('1.1.0', 1);
        runToEnd($runner);

        uploadedRelease($this->tmp, $this->updates, $this->secret, overrides: ['app/Nuevo.php' => '<?php // clase nueva, otro build'], build: 2);
        $runner->start('1.1.0', 1);
        $final = runToEnd($runner);

        expect($final['ok'])->toBeTrue()
            ->and(file_get_contents($this->root.'/app/Nuevo.php'))->toBe('<?php // clase nueva, otro build')
            ->and(json_decode(file_get_contents($this->updates.'/installed.json'), true)['build'])->toBe(2);
    });

    it('si la migración falla, restaura la base de datos, devuelve el código anterior y quita el mantenimiento', function () {
        uploadedRelease($this->tmp, $this->updates, $this->secret);
        $before = treeOf($this->root);
        $this->hooks->failMigration = true;
        $runner = app(RunUpdate::class);

        $runner->start('1.1.0', 1);
        $final = runToEnd($runner);

        expect($final['phase'])->toBe('rolled_back')
            ->and($final['ok'])->toBeFalse()
            ->and($final['error'])->toContain('La migración 2026_11_21_000000 falló.')
            ->and($this->hooks->calls)->toBe(['down', 'backup:antes-de-v1.1.0', 'migrate', 'restore:antes-de-v1.1.0.sql.gz', 'clear', 'up'])
            ->and(treeOf($this->root))->toBe($before)
            ->and($runner->isActive())->toBeFalse()
            ->and(json_decode(file_get_contents($this->updates.'/installed.json'), true)['version'])->toBe('1.0.0')
            ->and(file_get_contents($this->updates.'/history.log'))->toContain('revertida');
    });

    it('si el paquete se daña después de verificarlo, falla antes de tocar el código y no restaura la base de datos', function () {
        uploadedRelease($this->tmp, $this->updates, $this->secret);
        $before = treeOf($this->root);
        $runner = app(RunUpdate::class);
        $runner->start('1.1.0', 1);

        $part = $this->updates.'/inbox/v1.1.0/app-v1.1.0.zip.002';
        $bytes = file_get_contents($part);
        file_put_contents($part, substr($bytes, 0, 100).random_bytes(200).substr($bytes, 300));

        $final = runToEnd($runner);

        expect($final['phase'])->toBe('rolled_back')
            ->and(treeOf($this->root))->toBe($before)
            ->and($this->hooks->calls)->not->toContain('migrate')
            ->and($this->hooks->calls)->toContain('up')
            ->and(implode(' ', $this->hooks->calls))->not->toContain('restore');
    });

    it('los archivos protegidos que sí viajan en el paquete (.env.example, bootstrap/cache/.gitignore) se ignoran o se reemplazan según corresponda', function () {
        uploadedRelease($this->tmp, $this->updates, $this->secret, overrides: [
            '.env.example' => 'APP_NAME=Nuevo',
            'bootstrap/cache/.gitignore' => "*\n!.gitignore\n",
        ]);
        file_put_contents($this->root.'/.env.example', 'APP_NAME=Viejo');
        $runner = app(RunUpdate::class);

        $runner->start('1.1.0', 1);
        $final = runToEnd($runner);

        expect($final['ok'])->toBeTrue()
            ->and(file_get_contents($this->root.'/.env.example'))->toBe('APP_NAME=Nuevo')
            ->and(file_get_contents($this->root.'/.env'))->toBe('APP_KEY=secreto')
            ->and(file_get_contents($this->root.'/bootstrap/cache/config.php'))->toBe('<?php // caché local')
            ->and(is_file($this->root.'/bootstrap/cache/.gitignore'))->toBeFalse();
    });

    it('permite revertir o cancelar una instalación en curso y no empezar otra mientras tanto', function () {
        uploadedRelease($this->tmp, $this->updates, $this->secret);
        $runner = app(RunUpdate::class);

        $runner->start('1.1.0', 1);
        $runner->step();
        $runner->step();

        expect(fn () => $runner->start('1.1.0', 1))->toThrow(RuntimeException::class, 'Ya hay una instalación en curso');

        $reverted = $runner->revert();

        expect($reverted['phase'])->toBe('rolled_back')
            ->and($this->hooks->calls)->toBe(['down', 'backup:antes-de-v1.1.0', 'clear', 'up'])
            ->and(trim(file_get_contents($this->root.'/VERSION')))->toBe('1.0.0')
            ->and(fn () => $runner->revert())->toThrow(RuntimeException::class, 'No hay una instalación en curso');
    });

    it('no empieza si el paquete no es instalable', function () {
        expect(fn () => app(RunUpdate::class)->start('1.1.0', 1))->toThrow(RuntimeException::class, 'No hay archivos subidos');

        expect($this->hooks->calls)->toBe([])
            ->and(app(RunUpdate::class)->state())->toBeNull();
    });
});

describe('página y rutas de /admin/actualizaciones', function () {
    it('exige iniciar sesión y el permiso updates.manage en todas las rutas', function () {
        $routes = [
            ['get', route('updates.index')],
            ['post', route('updates.upload')],
            ['post', route('updates.inspect')],
            ['post', route('updates.start')],
            ['post', route('updates.step')],
            ['post', route('updates.revert')],
            ['delete', route('updates.discard', '1.1.0')],
        ];

        foreach ($routes as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }

        $this->actingAs(userWithRole(RoleName::Cliente));

        foreach ($routes as [$method, $url]) {
            $this->{$method}($url)->assertForbidden();
        }
    });

    it('muestra la página al administrador con su enlace en el menú, y el enlace no aparece al cliente', function () {
        $this->actingAs(userWithRole(RoleName::Admin))
            ->get(route('updates.index'))
            ->assertOk()
            ->assertSee('Versión instalada')
            ->assertSee('1.0.0')
            ->assertSee(route('updates.index'));

        $this->actingAs(userWithRole(RoleName::Cliente))
            ->get(route('client.dashboard'))
            ->assertDontSee(route('updates.index'));
    });

    it('sube partes con nombre de paquete y rechaza las demás, dejando constancia', function () {
        $this->actingAs(userWithRole(RoleName::Admin));

        $this->postJson(route('updates.upload'), ['version' => '1.1.0', 'file' => UploadedFile::fake()->create('manifest.json', 1)])
            ->assertOk()
            ->assertJson(['present' => ['manifest.json']]);

        $this->postJson(route('updates.upload'), ['version' => '1.1.0', 'file' => UploadedFile::fake()->create('shell.php', 1)])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'no es un archivo de actualización'));

        $this->postJson(route('updates.upload'), ['version' => '1.1', 'file' => UploadedFile::fake()->create('manifest.json', 1)])
            ->assertJsonValidationErrors('version');

        $this->postJson(route('updates.upload'), ['version' => '1.1.0', 'file' => UploadedFile::fake()->create('app-v1.1.0.zip.001', 11)])
            ->assertJsonValidationErrors('file');

        expect(is_file($this->updates.'/inbox/v1.1.0/shell.php'))->toBeFalse()
            ->and(collect(securityLog())->where('message', 'update.part_uploaded')->count())->toBe(1);
    });

    it('verifica un paquete subido y descarta uno que no se quiere', function () {
        uploadedRelease($this->tmp, $this->updates, $this->secret);
        $this->actingAs(userWithRole(RoleName::Admin));

        $this->postJson(route('updates.inspect'), ['version' => '1.1.0'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('summary.version', '1.1.0')
            ->assertJsonPath('installed.version', '1.0.0');

        $this->deleteJson(route('updates.discard', '1.1.0'))->assertOk()->assertJson(['discarded' => '1.1.0']);

        expect(is_dir($this->updates.'/inbox/v1.1.0'))->toBeFalse();

        $this->postJson(route('updates.inspect'), ['version' => '1.1.0'])->assertJsonPath('ok', false);
    });

    it('instala paso a paso por HTTP: pide la contraseña, solo la avanza quien la empezó y deja constancia', function () {
        uploadedRelease($this->tmp, $this->updates, $this->secret);
        $admin = userWithRole(RoleName::Admin);
        $this->actingAs($admin);

        $this->postJson(route('updates.step'))->assertStatus(409);
        $this->postJson(route('updates.start'), ['version' => '1.1.0', 'current_password' => 'equivocada'])->assertJsonValidationErrors('current_password');

        expect(app(RunUpdate::class)->state())->toBeNull();

        $this->postJson(route('updates.start'), ['version' => '1.1.0', 'current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('phase', 'maintenance');

        $this->actingAs(userWithRole(RoleName::Admin))->postJson(route('updates.step'))->assertForbidden();

        $this->actingAs($admin);

        for ($turn = 0, $last = null; $turn < 60; $turn++) {
            $last = $this->postJson(route('updates.step'));
            $last->assertOk();

            if ($last->json('finished')) {
                break;
            }
        }

        $events = collect(securityLog())->pluck('message');

        expect($last->json('ok'))->toBeTrue()
            ->and(trim(file_get_contents($this->root.'/VERSION')))->toBe('1.1.0')
            ->and($this->hooks->calls)->toBe(['down', 'backup:antes-de-v1.1.0', 'migrate', 'clear', 'up'])
            ->and($events)->toContain('update.started')
            ->and($events)->toContain('update.finished');
    });

    it('revierte una instalación en curso con la contraseña', function () {
        uploadedRelease($this->tmp, $this->updates, $this->secret);
        $this->actingAs(userWithRole(RoleName::Admin));

        $this->postJson(route('updates.start'), ['version' => '1.1.0', 'current_password' => 'password'])->assertOk();
        $this->postJson(route('updates.step'))->assertOk();

        $this->postJson(route('updates.revert'), ['current_password' => 'equivocada'])->assertJsonValidationErrors('current_password');
        $this->postJson(route('updates.revert'), ['current_password' => 'password'])->assertOk()->assertJsonPath('phase', 'rolled_back');
        $this->postJson(route('updates.revert'), ['current_password' => 'password'])->assertStatus(422);

        expect(collect(securityLog())->pluck('message'))->toContain('update.reverted')
            ->and(trim(file_get_contents($this->root.'/VERSION')))->toBe('1.0.0');
    });

    it('durante el mantenimiento sigue disponible el actualizador y nada más', function () {
        $this->actingAs(userWithRole(RoleName::Admin));
        app()->maintenanceMode()->activate(['retry' => 60]);

        try {
            $this->get(route('updates.index'))->assertOk();
            $this->get(route('home'))->assertStatus(503);
        } finally {
            app()->maintenanceMode()->deactivate();
        }
    });
});
