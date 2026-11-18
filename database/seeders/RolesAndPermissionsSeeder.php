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
     * Crea los permisos y roles definidos en los enums y elimina los roles que ya no existen en
     * RoleName (por ejemplo "invitado": el visitante sin cuenta no es un rol). Quien tuviera uno de
     * esos roles queda sin rol, es decir, sin permisos. Puede ejecutarse varias veces.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::whereNotIn('name', array_column(RoleName::cases(), 'value'))->delete();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value);
        }

        // DatabaseSeeder usa WithoutModelEvents: sin eventos Spatie no renueva su caché al crear los
        // permisos y syncPermissions no los encontraría en una base vacía (migrate:fresh --seed).
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (RoleName::cases() as $role) {
            Role::findOrCreate($role->value)->syncPermissions($role->permissions());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
