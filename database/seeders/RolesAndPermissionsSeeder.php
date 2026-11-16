<?php

namespace Database\Seeders;

use App\PermissionName;
use App\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Crea los permisos y roles definidos en los enums. Puede ejecutarse varias veces.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value);
        }

        foreach (RoleName::cases() as $role) {
            Role::findOrCreate($role->value)->syncPermissions($role->permissions());
        }
    }
}
