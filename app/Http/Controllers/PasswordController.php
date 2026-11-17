<?php

namespace App\Http\Controllers;

use App\Actions\ConfirmPassword;
use App\Http\Requests\PasswordUpdateRequest;
use Illuminate\Http\RedirectResponse;

class PasswordController extends Controller
{
    public function update(PasswordUpdateRequest $request, ConfirmPassword $confirm): RedirectResponse
    {
        $confirm->handle($request, $request->validated('current_password'));

        $request->user()->update(['password' => $request->validated('password')]);

        return to_route('profile.edit')->with('success', 'Contraseña actualizada.');
    }
}
