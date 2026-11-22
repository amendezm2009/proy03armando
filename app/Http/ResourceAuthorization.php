<?php

namespace App\Http;

use App\PermissionName;
use Illuminate\Routing\PendingResourceRegistration;
use Spatie\Permission\Middleware\PermissionMiddleware;

/**
 * Tabla única de permisos de los controladores de recurso, para la web y la API.
 *
 * La autorización se declara en las rutas; los controladores y Form Requests no la repiten.
 *
 * CÓMO PROTEGER UN RECURSO COMPLETO
 *
 *   ResourceAuthorization::apply(Route::resource('products', ProductController::class), 'products');
 *
 *   Cada acción exige el permiso "{recurso}.{habilidad}" según ACTIONS_BY_ABILITY, así que
 *   App\PermissionName debe tener los casos products.view, .create, .update y .delete.
 *
 * CÓMO PROTEGER UNA RUTA SUELTA (exportar, importar, aprobar...)
 *
 *   Route::get('products/export', [ProductController::class, 'export'])
 *       ->middleware(ResourceAuthorization::needs(PermissionName::ExportProducts));
 *
 * CÓMO AÑADIR UN PRIVILEGIO NUEVO
 *
 *   1. Nuevo caso en App\PermissionName:  ExportProducts = 'products.export'
 *   2. Asignarlo a los roles en App\RoleName::permissions()
 *   3. php artisan db:seed --class=RolesAndPermissionsSeeder
 *   4. Exigirlo en la ruta con needs() y, en la vista, mostrar el botón solo a quien lo tenga:
 *      Blade: @can(App\PermissionName::ExportProducts->value) ... @endcan
 *
 * CÓMO AÑADIR UNA REGLA QUE DEPENDE DEL REGISTRO
 *
 *   Se escribe una policy con ese método (php artisan make:policy OrderPolicy) y se suma en la ruta:
 *
 *   Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])
 *       ->middleware(ResourceAuthorization::needs(PermissionName::CancelOrders))
 *       ->can('cancel', 'order');
 *
 * ERRORES
 *
 *   Si al recurso le falta un permiso en PermissionName, apply() lanza ValueError al cargar
 *   las rutas; el olvido se nota al arrancar la aplicación, no cuando un usuario entra.
 */
final class ResourceAuthorization
{
    /**
     * Acciones del controlador que exige cada habilidad.
     *
     * @var array<string, list<string>>
     */
    private const array ACTIONS_BY_ABILITY = [
        'view' => ['index', 'show'],
        'create' => ['create', 'store'],
        'update' => ['edit', 'update'],
        'delete' => ['destroy'],
    ];

    /**
     * Middleware que exige un permiso; responde 403 a quien no lo tiene.
     */
    public static function needs(PermissionName $permission): string
    {
        return PermissionMiddleware::using($permission);
    }

    /**
     * Protege todas las acciones de un recurso con los permisos "{recurso}.{habilidad}".
     *
     * $abilities limita qué habilidades se exigen cuando el recurso no tiene las cuatro; por ejemplo,
     * usuarios no se eliminan, solo se desactivan: apply($routes, 'users', ['view', 'create', 'update']).
     *
     * @param  array<int, string>  $abilities
     */
    public static function apply(
        PendingResourceRegistration $routes,
        string $resource,
        array $abilities = ['view', 'create', 'update', 'delete'],
    ): PendingResourceRegistration {
        foreach (array_intersect_key(self::ACTIONS_BY_ABILITY, array_flip($abilities)) as $ability => $actions) {
            $routes->middlewareFor($actions, self::needs(PermissionName::from("{$resource}.{$ability}")));
        }

        return $routes;
    }
}
