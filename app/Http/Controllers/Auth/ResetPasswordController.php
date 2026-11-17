<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ResetPasswordController extends Controller
{
    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
            // Solo cambia los textos de la pantalla (la invitación de bienvenida lleva ?invitation=1);
            // no consulta la base de datos, así que no revela nada sobre la cuenta.
            'invitation' => $request->boolean('invitation'),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(ResetPasswordRequest $request): RedirectResponse
    {
        $acceptedInvitation = false;

        $status = Password::reset([...$request->validated(), 'is_active' => true], function (User $user, string $password) use (&$acceptedInvitation): void {
            // Definir la contraseña con el enlace (de bienvenida o de recuperación) acepta la invitación.
            $acceptedInvitation = $user->hasPendingInvitation();

            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'invitation_accepted_at' => $acceptedInvitation ? now() : $user->invitation_accepted_at,
            ])->save();

            if ($acceptedInvitation) {
                Log::info('Invitación aceptada.', ['user_id' => $user->id]);
            }

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            $expired = $status === Password::INVALID_TOKEN && $request->boolean('invitation');

            throw ValidationException::withMessages([
                'email' => $expired ? __('passwords.invitation_token') : __($status),
            ]);
        }

        return to_route('login')->with('success', $acceptedInvitation ? __('passwords.invitation_accepted') : __($status));
    }
}
