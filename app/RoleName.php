<?php

namespace App;

enum RoleName: string
{
    // Después de cualquier cambio en este archivo hay que ejecutar otra vez el seeder;
    // php artisan db:seed --class=RolesAndPermissionsSeeder
    case Admin = 'admin';
    case Cliente = 'cliente';
    case Invitado = 'invitado';

    /**
     * Permisos que el seeder asigna a cada rol.
     * 
     * @return list<PermissionName>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => PermissionName::cases(),
            // Si algún día el admin no debe tener todo, sustituye PermissionName::cases() 
            // por una lista explícita, igual que la del cliente, o quita los que no correspondan:
            // self::Admin => array_filter(
            //     PermissionName::cases(),
            //     fn (PermissionName $permission) => $permission !== PermissionName::DeleteProducts,
            // ),
            self::Cliente => [PermissionName::ViewClassifications],
            self::Invitado => [],
        };
    }
}
