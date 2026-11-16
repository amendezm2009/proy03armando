<?php

namespace App;

enum RoleName: string
{
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
            self::Admin => [PermissionName::cases()],
            self::Cliente => [PermissionName::ViewClassifications],
            self::Invitado => [],
        };
    }
}
