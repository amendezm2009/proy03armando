<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Desactiva o reactiva una cuenta.
 *
 * Al desactivar se corta todo acceso vivo: se revocan sus tokens de la API, se invalida el
 * "recordarme" y se borran sus sesiones web guardadas (si las sesiones están en la base de datos;
 * con otro controlador de sesión, EnsureUserIsActive las cierra en la siguiente petición).
 * Las reglas de quién puede hacerlo (no a sí mismo, no a la última persona administradora)
 * están en App\Policies\UserPolicy.
 */
class ChangeUserStatus
{
    public function handle(User $user, User $actor, bool $active): User
    {
        DB::transaction(function () use ($user, $active): void {
            $user->forceFill(['is_active' => $active]);

            if (! $active) {
                $user->forceFill(['remember_token' => Str::random(60)]);
            }

            $user->save();

            if (! $active) {
                $user->tokens()->delete();

                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
                }
            }
        });

        Log::info($active ? 'Usuario reactivado.' : 'Usuario desactivado.', [
            'actor_id' => $actor->id,
            'user_id' => $user->id,
        ]);

        return $user;
    }
}
