<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\RoleName;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = User::create($request->validated())->assignRole(RoleName::Cliente);

        event(new Registered($user));

        Auth::login($user);

        $request->session()->regenerate();

        return to_route($user->homeRoute())->with('success', 'Cuenta creada. ¡Bienvenido!');
    }
}
