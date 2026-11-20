<?php

namespace App\Http\Controllers;

use App\Actions\ConfirmPassword;
use App\Actions\RecordSecurityEvent;
use App\Http\Requests\PasswordUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class PasswordController extends Controller
{
    public function update(PasswordUpdateRequest $request, ConfirmPassword $confirm): RedirectResponse
    {
        $confirm->handle($request, $request->validated('current_password'));

        $request->user()->update(['password' => $request->validated('password')]);

        // La contraseña cambió: las demás sesiones web y los tokens de la API de esta cuenta dejan de valer. La
        // sesión actual sigue abierta (logoutOtherDevices actualiza la huella que guarda).
        Auth::logoutOtherDevices($request->validated('password'));
        $request->user()->tokens()->delete();

        app(RecordSecurityEvent::class)->handle('auth.password_changed', request: $request);

        return to_route('profile.edit')->with('success', 'Contraseña actualizada.');
    }
}
