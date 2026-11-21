<?php

use App\Actions\BackupDatabase;
use App\Actions\RestoreDatabase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Los respaldos se prueban contra una base SQLite en un archivo propio (conexión «respaldo»), no contra la base de
 * pruebas en memoria: así restaurar puede borrar y recrear tablas sin tocar el esquema de la aplicación.
 */
const TRICKY_TEXT = "O'Brien; \"comillas\"\nsegunda línea con \\ barra y ñandú ✓";

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'proy03-backup-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->tmp);

    config([
        'database.connections.respaldo' => ['driver' => 'sqlite', 'database' => $this->tmp.'/tienda.sqlite', 'prefix' => '', 'foreign_key_constraints' => false],
        'backup.directory' => $this->tmp.'/backups',
        'backup.keep' => 10,
        'backup.exclude_data' => ['sessions'],
        'backup.rows_per_insert' => 2,
    ]);

    touch($this->tmp.'/tienda.sqlite');

    Schema::connection('respaldo')->create('personas', function ($table) {
        $table->id();
        $table->string('nombre');
        $table->text('notas')->nullable();
        $table->decimal('saldo', 8, 2)->default(0);
        $table->boolean('activa')->default(true);
    });
    Schema::connection('respaldo')->create('sessions', function ($table) {
        $table->string('id')->primary();
        $table->text('payload');
    });

    DB::connection('respaldo')->table('personas')->insert([
        ['nombre' => 'Ana', 'notas' => TRICKY_TEXT, 'saldo' => 10.5, 'activa' => true],
        ['nombre' => 'Luis', 'notas' => null, 'saldo' => 0, 'activa' => false],
        ['nombre' => 'Marta', 'notas' => '', 'saldo' => 1234.56, 'activa' => true],
    ]);
    DB::connection('respaldo')->table('sessions')->insert(['id' => 'abc', 'payload' => 'datos temporales']);
});

afterEach(function () {
    Carbon::setTestNow();
    DB::purge('respaldo');
    File::deleteDirectory($this->tmp);
});

/**
 * @return list<array<string, mixed>>
 */
function personas(): array
{
    return DB::connection('respaldo')->table('personas')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
}

describe('BackupDatabase', function () {
    it('guarda el volcado comprimido con su .json, sin los datos de las tablas temporales', function () {
        $result = app(BackupDatabase::class)->handle('respaldo');
        $metadata = json_decode(file_get_contents($result['metadata']), true);

        expect(is_file($result['file']))->toBeTrue()
            ->and(basename($result['file']))->toMatch('/^\d{8}-\d{6}-v.+-tienda\.sql\.gz$/')
            ->and($result['tables'])->toBe(2)
            ->and($result['rows'])->toBe(3)
            ->and($result['sha256'])->toBe(hash_file('sha256', $result['file']))
            ->and($metadata['sha256'])->toBe($result['sha256'])
            ->and($metadata['size'])->toBe(filesize($result['file']))
            ->and($metadata['driver'])->toBe('sqlite')
            ->and($metadata['app_version'])->toBe(config('app.version'))
            ->and(collect($metadata['tables'])->firstWhere('name', 'sessions'))->toBe(['name' => 'sessions', 'rows' => 0, 'data' => false])
            ->and(collect($metadata['tables'])->firstWhere('name', 'personas')['rows'])->toBe(3);

        $dump = gzdecode(file_get_contents($result['file']));

        expect(file_get_contents($this->tmp.'/backups/.htaccess'))->toContain('Require all denied')->toContain('Deny from all');
        expect($dump)->toContain('DROP TABLE IF EXISTS "personas"')
            ->and($dump)->toContain('CREATE TABLE "personas"')
            ->and($dump)->not->toContain('datos temporales');
    });

    it('agrega la etiqueta al nombre, no pisa un respaldo del mismo segundo y lista del más reciente al más antiguo', function () {
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 10, 0, 0));
        $backup = app(BackupDatabase::class);

        $first = $backup->handle('respaldo', label: 'antes de actualizar');
        $second = $backup->handle('respaldo', label: 'antes de actualizar');
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 10, 5, 0));
        $third = $backup->handle('respaldo');

        expect(basename($first['file']))->toContain('-antes-de-actualizar.sql.gz')
            ->and($second['file'])->not->toBe($first['file'])
            ->and(array_column($backup->list(), 'path'))->toBe([$third['file'], $second['file'], $first['file']]);
    });

    it('conserva solo los respaldos más recientes según backup.keep', function () {
        config(['backup.keep' => 2]);
        $backup = app(BackupDatabase::class);
        $paths = [];

        foreach ([0, 1, 2] as $minute) {
            Carbon::setTestNow(Carbon::create(2026, 11, 21, 10, $minute, 0));
            $paths[] = $backup->handle('respaldo')['file'];
        }

        expect(is_file($paths[0]))->toBeFalse()
            ->and(is_file($paths[0].'.json'))->toBeFalse()
            ->and(is_file($paths[1]))->toBeTrue()
            ->and(is_file($paths[2]))->toBeTrue()
            ->and($backup->list())->toHaveCount(2);
    });

    it('rechaza un tipo de base de datos que no sabe volcar', function () {
        config(['database.connections.otra' => ['driver' => 'pgsql', 'host' => 'localhost', 'database' => 'x']]);

        expect(fn () => app(BackupDatabase::class)->handle('otra'))->toThrow(RuntimeException::class, 'solo admite');
    });
});

describe('RestoreDatabase', function () {
    it('devuelve la base al estado del respaldo, con textos que traen comillas, saltos de línea y «;»', function () {
        $original = personas();
        $result = app(BackupDatabase::class)->handle('respaldo');

        DB::connection('respaldo')->table('personas')->where('id', 1)->update(['nombre' => 'Cambiado']);
        DB::connection('respaldo')->table('personas')->delete(2);
        DB::connection('respaldo')->table('personas')->insert(['nombre' => 'Intrusa', 'notas' => 'x', 'saldo' => 1, 'activa' => true]);
        Schema::connection('respaldo')->drop('sessions');

        $restored = app(RestoreDatabase::class)->handle($result['file'], 'respaldo');

        expect(personas())->toBe($original)
            ->and(personas()[0]['notas'])->toBe(TRICKY_TEXT)
            ->and($restored['statements'])->toBeGreaterThan(4)
            ->and(Schema::connection('respaldo')->hasTable('sessions'))->toBeTrue()
            ->and(DB::connection('respaldo')->table('sessions')->count())->toBe(0)
            ->and(basename((string) $restored['safety']))->toContain('antes-de-restaurar');
    });

    it('antes de restaurar guarda un respaldo de seguridad del estado actual', function () {
        $result = app(BackupDatabase::class)->handle('respaldo');
        DB::connection('respaldo')->table('personas')->insert(['nombre' => 'Nueva', 'notas' => null, 'saldo' => 5, 'activa' => true]);

        $restored = app(RestoreDatabase::class)->handle($result['file'], 'respaldo');

        expect(personas())->toHaveCount(3)
            ->and(gzdecode(file_get_contents($restored['safety'])))->toContain("'Nueva'");
    });

    it('no restaura un respaldo alterado, sin su .json, de otro tipo de base de datos o inexistente', function () {
        $restore = app(RestoreDatabase::class);
        $result = app(BackupDatabase::class)->handle('respaldo');
        $original = personas();

        expect(fn () => $restore->handle($this->tmp.'/no-existe.sql.gz', 'respaldo'))->toThrow(RuntimeException::class, 'No existe');

        $bytes = file_get_contents($result['file']);
        file_put_contents($result['file'], substr($bytes, 0, -3));
        expect(fn () => $restore->handle($result['file'], 'respaldo'))->toThrow(RuntimeException::class, 'dañado o alterado');
        file_put_contents($result['file'], $bytes);

        $metadata = file_get_contents($result['metadata']);
        file_put_contents($result['metadata'], str_replace('"driver": "sqlite"', '"driver": "mysql"', $metadata));
        expect(fn () => $restore->handle($result['file'], 'respaldo'))->toThrow(RuntimeException::class, 'es de una base de datos mysql');

        unlink($result['metadata']);
        expect(fn () => $restore->handle($result['file'], 'respaldo'))->toThrow(RuntimeException::class, 'Falta');

        expect(personas())->toBe($original);
    });

    it('si el volcado está truncado y falla a mitad, devuelve la base de datos a como estaba', function () {
        $result = app(BackupDatabase::class)->handle('respaldo');
        DB::connection('respaldo')->table('personas')->insert(['nombre' => 'Posterior', 'notas' => null, 'saldo' => 7, 'activa' => true]);
        $beforeRestore = personas();

        $dump = gzdecode(file_get_contents($result['file']));
        $truncated = substr($dump, 0, (int) strrpos($dump, 'INSERT INTO')).'INSERT INTO "personas" ("id") VALUES (';
        file_put_contents($result['file'], gzencode($truncated));

        $metadata = json_decode(file_get_contents($result['metadata']), true);
        $metadata['size'] = filesize($result['file']);
        $metadata['sha256'] = hash_file('sha256', $result['file']);
        file_put_contents($result['metadata'], json_encode($metadata));

        expect(fn () => app(RestoreDatabase::class)->handle($result['file'], 'respaldo'))->toThrow(RuntimeException::class, 'truncado');

        expect(personas())->toBe($beforeRestore);
    });
});

describe('comandos', function () {
    it('db:backup crea el respaldo y db:backups lo lista', function () {
        $this->artisan('db:backups')->expectsOutputToContain('No hay respaldos')->assertSuccessful();

        $this->artisan('db:backup', ['--connection' => 'respaldo', '--label' => 'prueba'])
            ->expectsOutputToContain('Respaldo creado')
            ->expectsOutputToContain('SHA-256')
            ->assertSuccessful();

        $this->artisan('db:backups')->expectsOutputToContain('-prueba.sql.gz')->assertSuccessful();
    });

    it('db:restore pide confirmación y, con --yes, restaura por nombre de archivo', function () {
        $original = personas();
        $name = basename(app(BackupDatabase::class)->handle('respaldo')['file']);
        DB::connection('respaldo')->table('personas')->delete();

        $this->artisan('db:restore', ['file' => $name, '--connection' => 'respaldo'])
            ->expectsConfirmation('¿Restaurar ahora?', 'no')
            ->expectsOutputToContain('la base de datos no se tocó')
            ->assertSuccessful();

        expect(personas())->toBe([]);

        $this->artisan('db:restore', ['file' => $name, '--connection' => 'respaldo', '--yes' => true])
            ->expectsOutputToContain('Base de datos restaurada')
            ->assertSuccessful();

        expect(personas())->toBe($original);
    });

    it('db:restore avisa si el respaldo no existe y falla', function () {
        $this->artisan('db:restore', ['file' => 'no-existe.sql.gz', '--connection' => 'respaldo', '--yes' => true])
            ->expectsOutputToContain('No existe el respaldo')
            ->assertFailed();
    });
});
