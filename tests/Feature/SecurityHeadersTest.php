<?php

use App\Models\Product;
use App\RoleName;

/** Dirección del catálogo por HTTPS: así la petición llega como segura. */
function secureCatalogUrl(): string
{
    return 'https://localhost'.route('catalog.index', absolute: false);
}

describe('cabeceras de seguridad', function () {
    it('se envían en las páginas web', function () {
        $this->get(route('catalog.index'))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Permissions-Policy')
            ->assertHeaderMissing('X-Powered-By');
    });

    it('se envían también en las respuestas de la API y en los errores', function () {
        $this->getJson('/api/products')
            ->assertUnauthorized()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->get('/ruta-que-no-existe')
            ->assertNotFound()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    });

    it('envía HSTS solo por HTTPS y si está activo', function () {
        $this->get(route('catalog.index'))->assertHeaderMissing('Strict-Transport-Security');

        $this->get(secureCatalogUrl())
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        config(['security.hsts' => false]);

        $this->get(secureCatalogUrl())->assertHeaderMissing('Strict-Transport-Security');
    });
});

describe('política de seguridad de contenido', function () {
    it('se aplica en las páginas HTML con los orígenes que la aplicación usa', function () {
        $policy = $this->get(route('catalog.index'))->headers->get('Content-Security-Policy');

        expect($policy)
            ->toContain("default-src 'self'")
            ->toContain("object-src 'none'")
            ->toContain("frame-ancestors 'none'")
            ->toContain("base-uri 'self'")
            ->toContain("form-action 'self'")
            ->toContain('https://fonts.googleapis.com')
            ->not->toContain('unsafe-eval')
            ->not->toContain('upgrade-insecure-requests');

        preg_match('/script-src ([^;]+)/', $policy, $scripts);
        expect($scripts[1])->not->toContain('unsafe-inline');
    });

    it('usa un nonce distinto en cada respuesta y es el mismo de los scripts en línea', function () {
        $product = Product::factory()->create();

        $first = $this->get(route('catalog.show', $product));
        $second = $this->get(route('catalog.show', $product));

        preg_match("/'nonce-([^']+)'/", $first->headers->get('Content-Security-Policy'), $firstNonce);
        preg_match("/'nonce-([^']+)'/", $second->headers->get('Content-Security-Policy'), $secondNonce);

        expect($firstNonce[1])->not->toBe($secondNonce[1]);

        $first->assertSee('nonce="'.$firstNonce[1].'"', false);
    });

    it('no se envía en la API (JSON) y se puede pasar a solo informar o apagar', function () {
        $this->getJson('/api/products')->assertHeaderMissing('Content-Security-Policy');

        config(['security.csp' => 'report-only']);
        $this->get(route('catalog.index'))
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeader('Content-Security-Policy-Report-Only');

        config(['security.csp' => 'off']);
        $this->get(route('catalog.index'))
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeaderMissing('Content-Security-Policy-Report-Only');
    });

    it('con el servidor de Vite en marcha permite sus orígenes y su WebSocket, y nunca en producción', function () {
        $hot = tempnam(sys_get_temp_dir(), 'hot');
        file_put_contents($hot, 'http://[::1]:5173');
        Vite::useHotFile($hot);

        try {
            $policy = $this->get(route('catalog.index'))->headers->get('Content-Security-Policy');

            expect($policy)
                ->toContain("style-src 'self' 'unsafe-inline' https://fonts.googleapis.com http://[::1]:5173 http://localhost:5173 http://127.0.0.1:5173 http:;")
                ->toContain('ws://localhost:5173')
                ->toContain('connect-src \'self\' http://[::1]:5173 http://localhost:5173 http://127.0.0.1:5173 http: ws://[::1]:5173 ws://localhost:5173 ws://127.0.0.1:5173 ws:;');

            $this->app->detectEnvironment(fn (): string => 'production');

            expect($this->get(route('catalog.index'))->headers->get('Content-Security-Policy'))->not->toContain(':5173');
        } finally {
            unlink($hot);
        }
    });

    it('sube las peticiones inseguras a HTTPS cuando el sitio va por HTTPS', function () {
        expect($this->get(secureCatalogUrl())->headers->get('Content-Security-Policy'))
            ->toContain('upgrade-insecure-requests');
    });

    it('las páginas públicas no llevan scripts ni manejadores en línea sin nonce', function () {
        foreach ([route('home'), route('login'), route('register'), route('catalog.index')] as $page) {
            $html = $this->get($page)->getContent();

            expect($html)->not->toMatch('/<script(?![^>]*\bnonce=)(?![^>]*\bsrc=)[^>]*>/i')
                ->and($html)->not->toMatch('/\son(click|change|submit|load|error)\s*=/i');
        }
    });

    it('las páginas del panel tampoco', function () {
        $admin = userWithRole(RoleName::Admin);
        Product::factory()->count(2)->create();

        foreach ([route('admin.dashboard'), route('products.index'), route('products.create'), route('profile.edit')] as $page) {
            $html = $this->actingAs($admin)->get($page)->assertOk()->getContent();

            expect($html)->not->toMatch('/<script(?![^>]*\bnonce=)(?![^>]*\bsrc=)[^>]*>/i')
                ->and($html)->not->toMatch('/\son(click|change|submit|load|error)\s*=/i');
        }
    });
});
