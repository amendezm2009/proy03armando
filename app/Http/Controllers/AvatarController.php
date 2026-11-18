<?php

namespace App\Http\Controllers;

use App\Actions\RemoveAvatar;
use App\Actions\SaveAvatar;
use App\Http\Requests\AvatarRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Foto de perfil (web). Cada persona sube o quita la suya; un administrador solo puede quitar la de otra
 * (permiso users.update y UserPolicy::removeAvatar, en la ruta). Nadie sube la foto de otra persona.
 */
class AvatarController extends Controller
{
    public function update(AvatarRequest $request, SaveAvatar $save): RedirectResponse
    {
        $save->handle($request->user(), $request->file('avatar'));

        return to_route('profile.edit')->with('success', 'Foto de perfil actualizada.');
    }

    public function destroy(Request $request, RemoveAvatar $remove): RedirectResponse
    {
        $remove->handle($request->user(), $request->user());

        return to_route('profile.edit')->with('success', 'Quitamos tu foto de perfil.');
    }

    public function destroyFor(Request $request, User $user, RemoveAvatar $remove): RedirectResponse
    {
        $remove->handle($user, $request->user());

        return back()->with('success', "Quitamos la foto de {$user->name}.");
    }
}
