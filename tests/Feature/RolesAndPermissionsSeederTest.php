<?php

use App\Models\User;
use App\PermissionName;
use App\RoleName;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('crea solo los roles y permisos definidos en los enums', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::pluck('name')->sort()->values()->all())->toBe(['admin', 'cliente'])
        ->and(Permission::count())->toBe(count(PermissionName::cases()));
});

it('asigna al administrador todos los permisos y al cliente solo los suyos', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::findByName('admin')->permissions)->toHaveCount(count(PermissionName::cases()))
        ->and(Role::findByName('cliente')->permissions->pluck('name')->sort()->values()->all())
        ->toBe([PermissionName::ViewClassifications->value, PermissionName::ViewProducts->value]);
});

it('funciona en una base vacía aunque se ejecute sin eventos de modelo, como hace migrate --seed', function () {
    Model::withoutEvents(fn () => $this->seed(RolesAndPermissionsSeeder::class));

    expect(Role::findByName('admin')->permissions)->toHaveCount(count(PermissionName::cases()))
        ->and(Role::findByName('cliente')->permissions)->toHaveCount(count(RoleName::Cliente->permissions()));
});

it('el sembrado completo crea al administrador y al cliente con su rol', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'admin@example.com')->firstOrFail()->hasRole(RoleName::Admin->value))->toBeTrue()
        ->and(User::where('email', 'client@example.com')->firstOrFail()->hasRole(RoleName::Cliente->value))->toBeTrue();
});

it('puede ejecutarse varias veces sin duplicar nada', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::count())->toBe(count(RoleName::cases()))
        ->and(Permission::count())->toBe(count(PermissionName::cases()));
});

it('elimina un rol que ya no existe y deja sin rol, y sin permisos, a quien lo tenía', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Role::create(['name' => 'invitado']);
    $user = User::factory()->create()->assignRole('invitado');
    $cliente = User::factory()->create()->assignRole(RoleName::Cliente);

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::where('name', 'invitado')->exists())->toBeFalse()
        ->and($user->fresh()->getRoleNames()->all())->toBe([])
        ->and($user->fresh()->is_active)->toBeTrue()
        ->and($cliente->fresh()->getRoleNames()->all())->toBe([RoleName::Cliente->value]);

    $this->actingAs($user->fresh())->get(route('classifications.index'))->assertForbidden();
    $this->actingAs($cliente->fresh())->get(route('classifications.index'))->assertOk();
});
