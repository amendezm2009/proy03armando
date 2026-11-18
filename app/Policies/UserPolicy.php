<?php

namespace App\Policies;

use App\Models\User;

/**
 * Reglas por registro de la gestión de usuarios. El permiso del rol (users.deactivate, users.create...)
 * lo exigen las rutas con App\Http\ResourceAuthorization::needs(); aquí solo lo que depende de a
 * quién se aplica la acción. Se enlaza en la ruta con ->can('habilidad', 'user').
 */
class UserPolicy
{
    /**
     * Nadie se desactiva a sí mismo, y no se puede dejar al sistema sin una persona administradora activa.
     */
    public function deactivate(User $actor, User $target): bool
    {
        return $target->is_active
            && ! $actor->is($target)
            && ! $target->isLastActiveAdmin();
    }

    public function activate(User $actor, User $target): bool
    {
        return ! $target->is_active;
    }

    /**
     * Un administrador puede quitar la foto de otra persona que tenga una (la suya la quita desde su perfil).
     */
    public function removeAvatar(User $actor, User $target): bool
    {
        return $target->hasAvatar() && ! $actor->is($target);
    }

    /**
     * Solo se reenvía la invitación a otras cuentas activas que aún no definieron su contraseña.
     */
    public function invite(User $actor, User $target): bool
    {
        return $target->is_active && ! $actor->is($target) && $target->hasPendingInvitation();
    }
}
