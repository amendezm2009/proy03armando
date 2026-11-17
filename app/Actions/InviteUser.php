<?php

namespace App\Actions;

use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

/**
 * Envía a un usuario el correo con el enlace para definir su contraseña.
 *
 * Genera un token nuevo, así que un reenvío invalida el enlace anterior, y anota cuándo se envió
 * (invited_at). La invitación queda pendiente hasta que la persona define su contraseña con el
 * enlace (ResetPasswordController la marca como aceptada).
 * USO: app(InviteUser::class)->handle($user, $actor);
 */
class InviteUser
{
    public function handle(User $user, User $actor): void
    {
        $user->forceFill(['invited_at' => now()])->save();

        $user->notify(new WelcomeNotification(Password::createToken($user)));

        Log::info('Invitación enviada a un usuario.', ['actor_id' => $actor->id, 'user_id' => $user->id]);
    }
}
