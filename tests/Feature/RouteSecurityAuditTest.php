<?php

use App\Models\Classification;
use App\Models\Product;
use App\Models\User;
use App\RoleName;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * Auditoría de todas las rutas de la aplicación (OWASP A01 y CSRF). Si se agrega una ruta nueva sin protección,
 * estas pruebas fallan y piden decidir: protegerla o declararla aquí como pública a propósito.
 */

/** Rutas públicas a propósito: formularios de acceso, catálogo para visitantes y archivos que el navegador descarga. */
const PUBLIC_ROUTES = [
    'home', 'login', 'login.store', 'register', 'register.store', 'password.request', 'password.email',
    'password.reset', 'password.store', 'catalog.index', 'catalog.show', 'avatars.show', 'products.media.file', 'api.login',
];

/** Rutas para cualquier persona con sesión (sin permiso concreto): su propio perfil, su sesión y su token. */
const SESSION_ONLY_ROUTES = [
    'logout', 'profile.edit', 'profile.update', 'profile.avatar.update', 'profile.avatar.destroy',
    'profile.password.update', 'api.user', 'api.logout', 'api.user.avatar.update', 'api.user.avatar.destroy',
];

/**
 * @return list<LaravelRoute>
 */
function applicationRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (LaravelRoute $route): bool => str_starts_with($route->getActionName(), 'App\\'),
    ));
}

/**
 * @return list<string> Middleware ya resueltos (los grupos "web" y "api" expandidos).
 */
function routeMiddleware(LaravelRoute $route): array
{
    return array_values(array_map('strval', app('router')->gatherRouteMiddleware($route)));
}

function hasMiddleware(LaravelRoute $route, string $needle): bool
{
    return collect(routeMiddleware($route))->contains(fn (string $middleware): bool => str_contains($middleware, $needle));
}

/**
 * Nombres de las rutas (o direcciones) que no cumplen la condición.
 *
 * @param  list<LaravelRoute>  $routes
 * @param  Closure(LaravelRoute): bool  $passes
 * @return list<string>
 */
function routesFailing(array $routes, Closure $passes): array
{
    return array_values(array_map(
        fn (LaravelRoute $route): string => $route->getName() ?? $route->uri(),
        array_filter($routes, fn (LaravelRoute $route): bool => ! $passes($route)),
    ));
}

describe('autenticación y permisos', function () {
    it('toda ruta no pública exige iniciar sesión', function () {
        $protected = array_filter(applicationRoutes(), fn ($route) => ! in_array($route->getName(), PUBLIC_ROUTES, true));

        expect($protected)->not->toBeEmpty()
            ->and(routesFailing(array_values($protected), fn ($route) => hasMiddleware($route, 'Authenticate')))->toBeEmpty();
    });

    it('toda ruta con sesión, salvo el perfil propio, exige además un permiso', function () {
        $routes = array_filter(applicationRoutes(), fn ($route) => ! in_array($route->getName(), [...PUBLIC_ROUTES, ...SESSION_ONLY_ROUTES], true));

        expect($routes)->not->toBeEmpty()
            ->and(routesFailing(array_values($routes), fn ($route) => hasMiddleware($route, 'PermissionMiddleware')))->toBeEmpty();
    });

    it('la API protegida usa Sanctum y solo las rutas públicas declaradas quedan abiertas', function () {
        $api = array_filter(applicationRoutes(), fn ($route) => str_starts_with($route->uri(), 'api/') && $route->getName() !== 'api.login');

        expect(routesFailing(array_values($api), fn ($route) => hasMiddleware($route, 'Authenticate:sanctum')))->toBeEmpty();
    });

    it('toda ruta con sesión descarta las cuentas desactivadas', function () {
        $routes = array_filter(applicationRoutes(), fn ($route) => hasMiddleware($route, 'Authenticate'));

        expect(routesFailing(array_values($routes), fn ($route) => hasMiddleware($route, 'EnsureUserIsActive')))->toBeEmpty();
    });

    it('los accesos y la recuperación de contraseña tienen límite de intentos', function () {
        $credentialRoutes = array_filter(applicationRoutes(), fn ($route) => in_array($route->getName(), ['login.store', 'register.store', 'password.email', 'password.store', 'api.login'], true));

        expect($credentialRoutes)->toHaveCount(5)
            ->and(routesFailing(array_values($credentialRoutes), fn ($route) => hasMiddleware($route, 'ThrottleRequests')))->toBeEmpty();
    });
});

describe('sin sesión', function () {
    it('cada ruta protegida responde con el login (web) o 401 (API) y nunca con datos', function () {
        $protected = array_filter(applicationRoutes(), fn ($route) => ! in_array($route->getName(), PUBLIC_ROUTES, true));
        $unexpected = [];

        foreach ($protected as $route) {
            $method = collect($route->methods())->reject(fn (string $method) => $method === 'HEAD')->first();
            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $isApi = str_starts_with($route->uri(), 'api/');

            $response = $isApi ? $this->json($method, $uri) : $this->call($method, $uri);

            $ok = $isApi ? $response->status() === 401 : ($response->isRedirect(route('login')));

            if (! $ok) {
                $unexpected[] = "{$method} {$route->uri()} -> {$response->status()}";
            }
        }

        expect($unexpected)->toBeEmpty();
    });
});

describe('CSRF', function () {
    it('toda ruta web que cambia datos pasa por la verificación del token', function () {
        $routes = array_filter(
            applicationRoutes(),
            fn ($route) => ! str_starts_with($route->uri(), 'api/') && array_diff($route->methods(), ['GET', 'HEAD']) !== [],
        );

        expect($routes)->not->toBeEmpty()
            ->and(routesFailing(array_values($routes), fn ($route) => hasMiddleware($route, 'PreventRequestForgery')))->toBeEmpty();
    });

    it('todo formulario POST de las páginas lleva su token @csrf', function () {
        $admin = userWithRole(RoleName::Admin);
        $product = Product::factory()->create();
        $classification = Classification::factory()->create();
        $other = User::factory()->create();

        $guestPages = [route('login'), route('register'), route('password.request'), route('password.reset', 'token')];
        $adminPages = [
            route('products.index'), route('products.create'), route('products.edit', $product), route('products.show', $product),
            route('classifications.index'), route('classifications.create'), route('classifications.edit', $classification),
            route('users.index'), route('users.create'), route('users.edit', $other), route('profile.edit'),
        ];

        foreach ([...$guestPages, ...$adminPages] as $page) {
            $isGuestPage = in_array($page, $guestPages, true);
            $html = ($isGuestPage ? $this : $this->actingAs($admin))->get($page)->assertOk()->getContent();

            $forms = preg_match_all('/<form\b[^>]*\bmethod="POST"/i', $html);
            $tokens = preg_match_all('/<input\b[^>]*\bname="_token"/i', $html);

            expect($forms)->toBeGreaterThan(0, $page)->and($tokens)->toBe($forms, $page);
        }
    });

    it('ninguna dirección está exceptuada de la verificación del token', function () {
        expect(app(PreventRequestForgery::class)->getExcludedPaths())->toBe([]);
    });

    it('la API no usa cookies de sesión: se autentica solo con token', function () {
        $api = array_filter(applicationRoutes(), fn ($route) => str_starts_with($route->uri(), 'api/'));

        expect(routesFailing(array_values($api), fn ($route) => ! hasMiddleware($route, 'StartSession')))->toBeEmpty();
    });
});
