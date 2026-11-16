<?php

use App\Http\Controllers\ClassificationController;
use App\Http\ResourceAuthorization;
use App\Models\Classification;
use App\Models\User;
use App\RoleName;
use Illuminate\Support\Facades\Route;

describe('cliente', function () {
    beforeEach(fn () => $this->actingAs(userWithRole(RoleName::Cliente)));

    it('ve el listado sin las acciones de escritura', function () {
        $classification = Classification::factory()->create();

        $this->get(route('classifications.index'))
            ->assertOk()
            ->assertSee($classification->name)
            ->assertDontSee(route('classifications.create'))
            ->assertDontSee(route('classifications.edit', $classification))
            ->assertDontSee('importModal');
    });

    it('recibe 403 en las acciones de escritura', function (string $method, string $uri) {
        $classification = Classification::factory()->create();

        $this->call($method, str_replace('{id}', (string) $classification->id, $uri))
            ->assertForbidden();
    })->with([
        'formulario de alta' => ['GET', '/classifications/create'],
        'alta' => ['POST', '/classifications'],
        'formulario de edición' => ['GET', '/classifications/{id}/edit'],
        'edición' => ['PUT', '/classifications/{id}'],
        'eliminación' => ['DELETE', '/classifications/{id}'],
        'importación' => ['POST', '/classifications/import'],
    ]);

    it('no elimina la clasificación', function () {
        $classification = Classification::factory()->create();

        $this->delete(route('classifications.destroy', $classification))->assertForbidden();

        $this->assertModelExists($classification);
    });
});

describe('administrador', function () {
    it('ve las acciones de escritura en el listado', function () {
        $classification = Classification::factory()->create();

        $this->actingAs(userWithRole(RoleName::Admin))
            ->get(route('classifications.index'))
            ->assertOk()
            ->assertSee(route('classifications.create'))
            ->assertSee(route('classifications.edit', $classification))
            ->assertSee('importModal');
    });
});

it('lanza un error al proteger un recurso que no tiene permisos definidos', function () {
    $routes = Route::resource('inexistentes', ClassificationController::class);

    expect(fn () => ResourceAuthorization::apply($routes, 'inexistentes'))->toThrow(ValueError::class);
});

describe('sin permiso de lectura', function () {
    it('recibe 403 en el listado con el rol invitado', function () {
        $this->actingAs(userWithRole(RoleName::Invitado))
            ->get(route('classifications.index'))
            ->assertForbidden();
    });

    it('recibe 403 en el listado sin ningún rol', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('classifications.index'))
            ->assertForbidden();
    });

    it('no ve el enlace de clasificaciones en el menú', function () {
        $this->actingAs(userWithRole(RoleName::Invitado))
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee(route('classifications.index'));
    });
});
