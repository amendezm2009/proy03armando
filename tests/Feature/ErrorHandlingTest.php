<?php

// test('example', function () {
//     $response = $this->get('/');

//     $response->assertStatus(200);
// });

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_falla-bd', fn () => DB::table('tabla_inexistente')->get());
});

it('muestra la página 404 personalizada para una ruta inexistente', function () {
    $this->get('/ruta-que-no-existe')
        ->assertNotFound()
        ->assertSee('Página no encontrada');
});

it('muestra la página 503 cuando falla la base de datos', function () {
    config(['app.debug' => false]);

    $this->get('/_falla-bd')
        ->assertStatus(503)
        ->assertSee('Servicio no disponible');
});

it('devuelve 503 en JSON cuando falla la base de datos', function () {
    config(['app.debug' => false]);

    $this->getJson('/_falla-bd')
        ->assertStatus(503)
        ->assertJsonPath('message', 'No se pudo completar la operación por un problema con la base de datos. Intenta nuevamente.');
});

it('conserva el error original cuando la aplicación está en modo debug', function () {
    config(['app.debug' => true]);

    $this->get('/_falla-bd')->assertStatus(500);
});
