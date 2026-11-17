<?php

namespace App\Http\Controllers;

use App\Actions\ChangeUserStatus;
use App\Actions\InviteUser;
use App\Actions\SaveUser;
use App\Http\Requests\UserIndexRequest;
use App\Http\Requests\UserRequest;
use App\Models\User;
use App\RoleName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Gestión de usuarios (web). Los permisos del rol los exigen las rutas (ResourceAuthorization) y
 * las reglas por registro (no a sí mismo, no a la última persona administradora) UserPolicy.
 */
class UserController extends Controller
{
    public function index(UserIndexRequest $request): View
    {
        $filters = $request->validated();

        return view('users.index', [
            'users' => User::filter($filters)
                ->with('roles')
                ->orderBy('name')
                ->paginate($filters['per_page'] ?? 10)
                ->withQueryString(),
            'filters' => $filters,
            'roles' => RoleName::cases(),
        ]);
    }

    public function create(): View
    {
        return view('users.create', ['roles' => RoleName::cases()]);
    }

    public function store(UserRequest $request, SaveUser $save): RedirectResponse
    {
        $save->handle($request->validated(), $request->user());

        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return to_route('users.index')->with(
            'success',
            "Usuario creado. Enviamos un correo a {$request->validated('email')} para que defina su contraseña; el enlace caduca en {$minutes} minutos."
        );
    }

    public function edit(User $user): View
    {
        return view('users.edit', ['user' => $user->load('roles'), 'roles' => RoleName::cases()]);
    }

    public function update(UserRequest $request, User $user, SaveUser $save): RedirectResponse
    {
        $save->handle($request->validated(), $request->user(), $user);

        return to_route('users.index')->with('success', 'Usuario actualizado.');
    }

    public function deactivate(Request $request, User $user, ChangeUserStatus $status): RedirectResponse
    {
        $status->handle($user, $request->user(), false);

        return back()->with('success', "Usuario {$user->name} desactivado. Se cerraron sus sesiones.");
    }

    public function activate(Request $request, User $user, ChangeUserStatus $status): RedirectResponse
    {
        $status->handle($user, $request->user(), true);

        return back()->with('success', "Usuario {$user->name} reactivado.");
    }

    public function invite(Request $request, User $user, InviteUser $invite): RedirectResponse
    {
        $invite->handle($user, $request->user());

        return back()->with('success', "Enviamos una nueva invitación a {$user->email}. El enlace anterior dejó de funcionar.");
    }
}
