<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Crea o actualiza un usuario y su rol; la usan el controlador web y el de la API.
 *
 * - Solo toma name y email del arreglo; el rol se aplica aparte con syncRoles y nunca por
 *   asignación masiva, y is_active / avatar_path / password no se pueden enviar desde fuera.
 * - Al crear: la contraseña es aleatoria y desconocida; se envía la invitación para que la
 *   persona defina la suya (InviteUser). Si el correo falla, no se crea el usuario.
 */
class SaveUser
{
    public function __construct(private InviteUser $invite) {}

    /**
     * @param  array{name?: string, email?: string, role?: string}  $data
     */
    public function handle(array $data, User $actor, ?User $user = null): User
    {
        return DB::transaction(function () use ($data, $actor, $user): User {
            $isNew = $user === null;
            $user ??= new User(['password' => Str::password(40)]);
            $previousRole = $isNew ? null : $user->getRoleNames()->first();

            $user->fill(Arr::only($data, ['name', 'email']))->save();

            if (isset($data['role'])) {
                $user->syncRoles($data['role']);
            }

            Log::info($isNew ? 'Usuario creado.' : 'Usuario actualizado.', [
                'actor_id' => $actor->id,
                'user_id' => $user->id,
                'role' => $data['role'] ?? $previousRole,
                'previous_role' => $previousRole,
            ]);

            if ($isNew) {
                $this->invite->handle($user, $actor);
            }

            return $user;
        });
    }
}
