<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Quita la foto de perfil (la persona vuelve a mostrar sus iniciales) y borra el archivo.
 *
 * USO: app(RemoveAvatar::class)->handle($user, $actor);   $actor es quien lo hizo (la propia
 * persona o un administrador) y solo sirve para el registro.
 */
class RemoveAvatar
{
    public function handle(User $user, User $actor): User
    {
        $path = $user->avatar_path;

        if ($path === null) {
            return $user;
        }

        $user->forceFill(['avatar_path' => null])->save();
        Storage::disk(SaveAvatar::DISK)->delete($path);

        Log::info('Foto de perfil eliminada.', ['actor_id' => $actor->id, 'user_id' => $user->id]);

        return $user;
    }
}
