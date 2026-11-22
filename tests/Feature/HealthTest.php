<?php

describe('/health', function () {
    it('responde ok al público sin decir la versión ni el entorno', function () {
        config(['release.health_token' => 'token-de-prueba']);

        $response = $this->getJson(route('health'))->assertOk()->assertExactJson(['status' => 'ok']);

        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('entrega la versión y las comprobaciones solo con el token correcto', function () {
        config(['release.health_token' => 'token-de-prueba']);

        $this->getJson(route('health'), ['X-Health-Token' => 'token-de-prueba'])
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'version' => config('app.version'),
                'environment' => 'testing',
                'checks' => ['database' => true],
            ]);

        $this->getJson(route('health'), ['X-Health-Token' => 'otro'])->assertExactJson(['status' => 'ok']);
    });

    it('sin HEALTH_TOKEN configurado nadie recibe la versión, ni con la cabecera vacía', function () {
        config(['release.health_token' => null]);

        $this->getJson(route('health'), ['X-Health-Token' => ''])->assertExactJson(['status' => 'ok']);
    });

    it('responde 503 si la base de datos no contesta', function () {
        $original = config('database.default');
        config(['database.default' => 'conexion-inexistente']);

        $response = $this->getJson(route('health'));

        config(['database.default' => $original]);

        $response->assertStatus(503)->assertExactJson(['status' => 'error']);
    });

    it('tiene límite de peticiones', function () {
        foreach (range(1, config('rate_limits.health')) as $intento) {
            $this->getJson(route('health'))->assertOk();
        }

        $this->getJson(route('health'))->assertStatus(429);
    });
});

it('la versión de la aplicación sale del archivo VERSION', function () {
    expect(config('app.version'))->toBe(trim(file_get_contents(base_path('VERSION'))))
        ->and(config('app.version'))->toMatch('/^\d+\.\d+\.\d+$/');
});
