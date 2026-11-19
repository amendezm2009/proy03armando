<?php

use App\Actions\ProbeVideo;
use App\Models\User;
use App\RoleName;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Datos válidos para crear un producto; los atributos indicados sustituyen a los de ejemplo.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function productPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Lámpara solar',
        'stock' => 10,
        'min_stock' => 2,
        'purchase_price' => '80.00',
        'sale_price' => '149.90',
    ], $overrides);
}

/**
 * Video MP4 falso: una cabecera "ftyp" real (el tipo se detecta por el contenido) rellenada con ceros hasta
 * los bytes indicados. No se puede reproducir; la duración la simula fakeProbe().
 */
function fakeMp4(int $bytes = 2048, string $name = 'clip.mp4'): UploadedFile
{
    $header = "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2mp41";

    return realUpload(str_pad($header, $bytes, "\0"), $name);
}

/**
 * Archivo subido de verdad, no simulado: su tipo se detecta por el CONTENIDO, como en producción
 * (UploadedFile::fake() lo deduce de la extensión del nombre, lo que no sirve para probar archivos disfrazados).
 */
function realUpload(string $content, string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($path, $content);
    register_shutdown_function(fn () => @unlink($path));

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * Video WebM falso: cabecera EBML con DocType "webm", rellenada con ceros.
 */
function fakeWebm(int $bytes = 2048, string $name = 'clip.webm'): UploadedFile
{
    $header = "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02";

    return realUpload(str_pad($header, $bytes, "\0"), $name);
}

/**
 * Sustituye el lector de duración de videos (getID3) por uno que devuelve siempre los segundos indicados
 * (null = "no se pudo leer"), para probar los límites sin videos reales.
 */
function fakeProbe(?float $seconds = 30.0): void
{
    app()->instance(ProbeVideo::class, new class($seconds) extends ProbeVideo
    {
        public function __construct(private readonly ?float $seconds) {}

        public function durationSeconds(string $path): ?float
        {
            return $this->seconds;
        }
    });
}

/**
 * Crea los roles y permisos y devuelve un usuario con el rol indicado.
 */
function userWithRole(RoleName $role): User
{
    test()->seed(RolesAndPermissionsSeeder::class);

    return User::factory()->create()->assignRole($role);
}
