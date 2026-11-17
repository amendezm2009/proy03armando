<?php

use App\Models\User;
use App\Notifications\WelcomeNotification;
use App\PermissionName;
use App\RoleName;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Datos válidos de un usuario nuevo.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function datosDeUsuario(array $overrides = []): array
{
    return ['name' => 'Ana Pérez', 'email' => 'ana@example.com', 'role' => RoleName::Cliente->value, 'current_password' => 'password', ...$overrides];
}

describe('acceso', function () {
    it('envía a los invitados al login', function () {
        $this->get(route('users.index'))->assertRedirect(route('login'));
    });

    it('niega con 403 la gestión de usuarios a quien no es administrador', function (string $rol, string $metodo, string $uri) {
        $objetivo = User::factory()->create();

        $this->actingAs($rol === 'sin rol' ? User::factory()->create() : userWithRole(RoleName::from($rol)))
            ->call($metodo, str_replace('{id}', (string) $objetivo->id, $uri))
            ->assertForbidden();
    })->with(function () {
        foreach (['cliente', 'sin rol'] as $rol) {
            foreach ([
                'listado' => ['GET', '/users'],
                'formulario de alta' => ['GET', '/users/create'],
                'alta' => ['POST', '/users'],
                'formulario de edición' => ['GET', '/users/{id}/edit'],
                'edición' => ['PUT', '/users/{id}'],
                'desactivar' => ['PATCH', '/users/{id}/deactivate'],
                'reactivar' => ['PATCH', '/users/{id}/activate'],
                'invitación' => ['POST', '/users/{id}/invitation'],
            ] as $accion => [$metodo, $uri]) {
                yield "{$rol}: {$accion}" => [$rol, $metodo, $uri];
            }
        }
    });

    it('muestra el enlace de usuarios solo a quien tiene el permiso', function () {
        $this->actingAs(userWithRole(RoleName::Admin))->get(route('home'))->assertSee(route('users.index'));

        $this->actingAs(userWithRole(RoleName::Cliente))->get(route('home'))->assertDontSee(route('users.index'));
    });

    it('no ofrece eliminar usuarios: la ruta no existe', function () {
        $this->actingAs(userWithRole(RoleName::Admin))
            ->delete('/users/'.User::factory()->create()->id)
            ->assertStatus(405);
    });
});

describe('listado', function () {
    beforeEach(fn () => $this->actingAs(userWithRole(RoleName::Admin)));

    it('muestra los usuarios con su rol y estado', function () {
        User::factory()->create(['name' => 'Luis Gómez'])->assignRole(RoleName::Cliente);
        User::factory()->inactive()->create(['name' => 'Eva Ruiz']);

        $this->get(route('users.index'))
            ->assertOk()
            ->assertSee('Luis Gómez')
            ->assertSee('Cliente')
            ->assertSee('Desactivado');
    });

    it('filtra por texto en el nombre y el correo', function () {
        User::factory()->create(['name' => 'Luis Gómez', 'email' => 'luis@example.com']);
        User::factory()->create(['name' => 'Eva Ruiz', 'email' => 'eva@example.com']);

        $this->get(route('users.index', ['search' => 'Luis']))->assertSee('Luis Gómez')->assertDontSee('Eva Ruiz');
        $this->get(route('users.index', ['search' => 'eva@']))->assertSee('Eva Ruiz')->assertDontSee('Luis Gómez');
    });

    it('filtra por rol y por estado', function () {
        User::factory()->create(['name' => 'Luis Gómez'])->assignRole(RoleName::Cliente);
        User::factory()->inactive()->create(['name' => 'Eva Ruiz']);

        $this->get(route('users.index', ['role' => 'cliente']))->assertSee('Luis Gómez')->assertDontSee('Eva Ruiz');
        $this->get(route('users.index', ['is_active' => '0']))->assertSee('Eva Ruiz')->assertDontSee('Luis Gómez');
        $this->get(route('users.index', ['is_active' => '1']))->assertSee('Luis Gómez')->assertDontSee('Eva Ruiz');
    });

    it('rechaza un rol o un estado inválidos', function () {
        $this->get(route('users.index', ['role' => 'superadmin', 'is_active' => 'quizas']))
            ->assertSessionHasErrors(['role', 'is_active']);
    });

    it('pagina el listado', function () {
        User::factory()->count(12)->create();

        $this->get(route('users.index', ['per_page' => 5]))->assertOk()->assertViewHas('users', fn ($users) => $users->count() === 5);
    });
});

describe('alta', function () {
    beforeEach(function () {
        Notification::fake();
        $this->admin = userWithRole(RoleName::Admin);
        $this->actingAs($this->admin);
    });

    it('crea el usuario con su rol y le envía la invitación', function () {
        $this->post(route('users.store'), datosDeUsuario())->assertRedirect(route('users.index'));

        $user = User::where('email', 'ana@example.com')->firstOrFail();

        expect($user->getRoleNames()->all())->toBe([RoleName::Cliente->value])
            ->and($user->is_active)->toBeTrue();
        Notification::assertSentTo($user, WelcomeNotification::class);
    });

    it('deja una contraseña aleatoria que nadie conoce', function () {
        $this->post(route('users.store'), datosDeUsuario(['password' => 'password']));

        $user = User::where('email', 'ana@example.com')->firstOrFail();

        expect(Hash::check('password', $user->password))->toBeFalse()
            ->and(password_get_info($user->password)['algoName'])->toBe('argon2id');
    });

    it('permite a la persona definir su contraseña con el enlace y entrar', function () {
        $this->post(route('users.store'), datosDeUsuario());
        $user = User::where('email', 'ana@example.com')->firstOrFail();

        $token = null;
        Notification::assertSentTo($user, WelcomeNotification::class, function (WelcomeNotification $notificacion) use (&$token, $user) {
            $token = $notificacion->token;

            return str_contains($notificacion->toMail($user)->actionUrl, route('password.reset', ['token' => $token]));
        });

        auth()->logout();

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => 'ana@example.com',
            'password' => 'clave-nueva-123',
            'password_confirmation' => 'clave-nueva-123',
        ])->assertRedirect(route('login'));

        $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'clave-nueva-123']);
        $this->assertAuthenticatedAs($user->fresh());
    });

    it('ignora los campos que no son name, email y role', function () {
        $this->post(route('users.store'), datosDeUsuario([
            'is_active' => 0,
            'avatar_path' => 'avatars/x.webp',
            'password' => 'hackeada-123',
            'email_verified_at' => now()->toDateTimeString(),
        ]));

        $user = User::where('email', 'ana@example.com')->firstOrFail();

        expect($user->is_active)->toBeTrue()
            ->and($user->avatar_path)->toBeNull()
            ->and($user->email_verified_at)->toBeNull()
            ->and(Hash::check('hackeada-123', $user->password))->toBeFalse();
    });

    it('valida los datos', function () {
        User::factory()->create(['email' => 'ana@example.com']);

        $this->post(route('users.store'), [])->assertSessionHasErrors(['name', 'email', 'role']);
        $this->post(route('users.store'), datosDeUsuario())->assertSessionHasErrors('email');
        $this->post(route('users.store'), datosDeUsuario(['email' => 'nueva@example.com', 'role' => 'superadmin']))
            ->assertSessionHasErrors('role');

        Notification::assertNothingSent();
    });
});

describe('edición', function () {
    beforeEach(function () {
        Notification::fake();
        $this->admin = userWithRole(RoleName::Admin);
        $this->actingAs($this->admin);
    });

    it('actualiza el nombre, el correo y el rol sin enviar invitación', function () {
        $user = User::factory()->create()->assignRole(RoleName::Cliente);

        $this->put(route('users.update', $user), datosDeUsuario(['role' => RoleName::Admin->value]))
            ->assertRedirect(route('users.index'));

        expect($user->fresh())
            ->name->toBe('Ana Pérez')
            ->email->toBe('ana@example.com')
            ->and($user->fresh()->getRoleNames()->all())->toBe([RoleName::Admin->value]);
        Notification::assertNothingSent();
    });

    it('rechaza el correo de otro usuario y permite conservar el propio', function () {
        User::factory()->create(['email' => 'ocupado@example.com']);
        $user = User::factory()->create(['email' => 'ana@example.com'])->assignRole(RoleName::Cliente);

        $this->put(route('users.update', $user), datosDeUsuario(['email' => 'ocupado@example.com']))
            ->assertSessionHasErrors('email');

        $this->put(route('users.update', $user), datosDeUsuario())->assertSessionHasNoErrors();
    });

    it('no deja a nadie cambiar su propio rol', function () {
        $this->put(route('users.update', $this->admin), datosDeUsuario(['email' => $this->admin->email, 'role' => RoleName::Cliente->value]))
            ->assertSessionHasErrors(['role' => 'No puedes cambiar tu propio rol.']);

        expect($this->admin->fresh()->hasRole(RoleName::Admin->value))->toBeTrue();
    });

    it('permite editar el propio nombre si el rol no cambia', function () {
        $this->put(route('users.update', $this->admin), datosDeUsuario(['email' => $this->admin->email, 'role' => RoleName::Admin->value]))
            ->assertSessionHasNoErrors();

        expect($this->admin->fresh()->name)->toBe('Ana Pérez');
    });

    it('permite quitar el rol de administrador a otro administrador si queda uno activo', function () {
        $otro = User::factory()->create()->assignRole(RoleName::Admin);

        $this->put(route('users.update', $otro), datosDeUsuario(['email' => $otro->email, 'role' => RoleName::Cliente->value]))
            ->assertSessionHasNoErrors();

        expect($otro->fresh()->hasRole(RoleName::Cliente->value))->toBeTrue();
    });

    it('no deja quitar el rol a la única persona administradora activa', function () {
        $unico = $this->admin;
        $gestor = User::factory()->create()->assignRole(RoleName::Cliente)->givePermissionTo(PermissionName::UpdateUsers);

        $this->actingAs($gestor)
            ->put(route('users.update', $unico), datosDeUsuario(['email' => $unico->email, 'role' => RoleName::Cliente->value]))
            ->assertSessionHasErrors(['role' => 'No se puede quitar el rol a la única persona administradora activa.']);

        expect($unico->fresh()->hasRole(RoleName::Admin->value))->toBeTrue();
    });
});

describe('desactivar y reactivar', function () {
    beforeEach(function () {
        $this->admin = userWithRole(RoleName::Admin);
        $this->actingAs($this->admin);
    });

    it('desactiva la cuenta, revoca sus tokens e invalida el recordarme', function () {
        $user = User::factory()->create()->assignRole(RoleName::Cliente);
        $user->createToken('api');
        $rememberAnterior = $user->remember_token;

        $this->patch(route('users.deactivate', $user), ['current_password' => 'password'])->assertRedirect()->assertSessionHas('success');

        expect($user->fresh())
            ->is_active->toBeFalse()
            ->remember_token->not->toBe($rememberAnterior)
            ->and($user->tokens()->count())->toBe(0);
    });

    it('borra las sesiones guardadas de la persona desactivada', function () {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $sesion = fn (string $id, ?int $userId) => DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time(),
        ]);
        $sesion('sesion-del-usuario', $user->id);
        $sesion('sesion-ajena', $this->admin->id);

        $this->patch(route('users.deactivate', $user), ['current_password' => 'password']);

        expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('sessions')->where('id', 'sesion-ajena')->count())->toBe(1);
    });

    it('impide iniciar sesión a una cuenta desactivada con el mismo mensaje que una contraseña incorrecta', function () {
        $user = User::factory()->inactive()->create();
        auth()->logout();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
    });

    it('cierra la sesión de quien la tenía abierta al desactivarse su cuenta', function () {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->forceFill(['is_active' => false])->save();

        $this->get(route('profile.edit'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', __('auth.inactive'));

        $this->assertGuest();
    });

    it('no deja desactivarse a sí mismo', function () {
        $this->patch(route('users.deactivate', $this->admin), ['current_password' => 'password'])->assertForbidden();

        expect($this->admin->fresh()->is_active)->toBeTrue();
    });

    it('no deja desactivar a la única persona administradora activa', function () {
        $gestor = User::factory()->create()->assignRole(RoleName::Cliente)->givePermissionTo(PermissionName::DeactivateUsers);

        $this->actingAs($gestor)->patch(route('users.deactivate', $this->admin), ['current_password' => 'password'])->assertForbidden();

        expect($this->admin->fresh()->is_active)->toBeTrue();
    });

    it('permite desactivar a otro administrador si queda uno activo', function () {
        $otro = User::factory()->create()->assignRole(RoleName::Admin);

        $this->patch(route('users.deactivate', $otro), ['current_password' => 'password'])->assertRedirect();

        expect($otro->fresh()->is_active)->toBeFalse();
    });

    it('niega desactivar una cuenta ya desactivada y reactivar una activa', function () {
        $inactivo = User::factory()->inactive()->create();
        $activo = User::factory()->create();

        $this->patch(route('users.deactivate', $inactivo), ['current_password' => 'password'])->assertForbidden();
        $this->patch(route('users.activate', $activo), ['current_password' => 'password'])->assertForbidden();
    });

    it('reactiva la cuenta y vuelve a permitir el acceso', function () {
        $user = User::factory()->inactive()->create();

        $this->patch(route('users.activate', $user), ['current_password' => 'password'])->assertRedirect()->assertSessionHas('success');
        auth()->logout();

        expect($user->fresh()->is_active)->toBeTrue();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user->fresh());
    });

    it('no envía enlaces de recuperación a una cuenta desactivada', function () {
        Notification::fake();
        $user = User::factory()->inactive()->create();
        auth()->logout();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('success', 'Si el correo está registrado, te enviamos un enlace para restablecer la contraseña.');

        Notification::assertNothingSent();
    });

    it('invalida un enlace emitido antes de la desactivación', function () {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $user->forceFill(['is_active' => false])->save();
        auth()->logout();

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'clave-nueva-123',
            'password_confirmation' => 'clave-nueva-123',
        ])->assertSessionHasErrors('email');

        expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    });
});

describe('roles disponibles', function () {
    beforeEach(function () {
        Notification::fake();
        $this->actingAs(userWithRole(RoleName::Admin));
    });

    it('solo ofrece administrador y cliente en los formularios y el filtro', function () {
        $usuario = User::factory()->create();

        foreach ([route('users.create'), route('users.edit', $usuario), route('users.index')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('value="admin"', false)
                ->assertSee('value="cliente"', false)
                ->assertDontSee('value="invitado"', false);
        }
    });

    it('rechaza el rol invitado al crear o editar, y como filtro', function () {
        $usuario = User::factory()->create()->assignRole(RoleName::Cliente);

        $this->post(route('users.store'), datosDeUsuario(['role' => 'invitado']))->assertSessionHasErrors('role');
        $this->put(route('users.update', $usuario), datosDeUsuario(['role' => 'invitado']))->assertSessionHasErrors('role');
        $this->get(route('users.index', ['role' => 'invitado']))->assertSessionHasErrors('role');

        expect($usuario->fresh()->getRoleNames()->all())->toBe([RoleName::Cliente->value]);
    });

    it('permite editar a quien no tiene rol, eligiendo uno', function () {
        $sinRol = User::factory()->create();

        $this->put(route('users.update', $sinRol), datosDeUsuario(['role' => '']))->assertSessionHasErrors('role');
        $this->put(route('users.update', $sinRol), datosDeUsuario())->assertSessionHasNoErrors();

        expect($sinRol->fresh()->getRoleNames()->all())->toBe([RoleName::Cliente->value]);
    });
});

describe('invitación', function () {
    beforeEach(function () {
        Notification::fake();
        $this->admin = userWithRole(RoleName::Admin);
        $this->actingAs($this->admin);
    });

    it('queda pendiente desde el alta, se avisa del correo y caducidad, y se muestra en el listado', function () {
        $minutos = config('auth.passwords.users.expire');

        $this->post(route('users.store'), datosDeUsuario())
            ->assertSessionHas('success', "Usuario creado. Enviamos un correo a ana@example.com para que defina su contraseña; el enlace caduca en {$minutos} minutos.");

        $user = User::where('email', 'ana@example.com')->firstOrFail();

        expect($user->hasPendingInvitation())->toBeTrue()
            ->and($user->invited_at)->not->toBeNull()
            ->and($user->invitation_accepted_at)->toBeNull();

        $this->get(route('users.index'))->assertSee('Invitación pendiente');
    });

    it('reenvía la invitación a una cuenta pendiente y avisa que el enlace anterior ya no sirve', function () {
        $user = User::factory()->invited()->create();

        $this->post(route('users.invitation', $user), ['current_password' => 'password'])
            ->assertRedirect()
            ->assertSessionHas('success', "Enviamos una nueva invitación a {$user->email}. El enlace anterior dejó de funcionar.");

        Notification::assertSentTo($user, WelcomeNotification::class);
    });

    it('actualiza la fecha de envío al reenviar', function () {
        $user = User::factory()->invited()->create(['invited_at' => now()->subDays(3)]);

        $this->post(route('users.invitation', $user), ['current_password' => 'password']);

        expect($user->fresh()->invited_at->isAfter(now()->subMinute()))->toBeTrue();
    });

    it('invalida el enlace anterior al reenviar', function () {
        $user = User::factory()->invited()->create();
        $anterior = Password::createToken($user);

        $this->post(route('users.invitation', $user), ['current_password' => 'password']);

        expect(Password::tokenExists($user, $anterior))->toBeFalse();
    });

    it('solo ofrece reenviar a quien tiene la invitación pendiente', function () {
        $pendiente = User::factory()->invited()->create();
        $normal = User::factory()->create();

        $this->get(route('users.index'))
            ->assertSee(route('users.invitation', $pendiente), ['current_password' => 'password'])
            ->assertDontSee(route('users.invitation', $normal), ['current_password' => 'password']);
    });

    it('niega reenviar a quien ya definió su contraseña o nunca fue invitado', function () {
        $aceptada = User::factory()->invited()->create(['invitation_accepted_at' => now()]);
        $normal = User::factory()->create();

        $this->post(route('users.invitation', $aceptada), ['current_password' => 'password'])->assertForbidden();
        $this->post(route('users.invitation', $normal), ['current_password' => 'password'])->assertForbidden();

        Notification::assertNothingSent();
    });

    it('no invita a una cuenta desactivada ni a uno mismo', function () {
        $inactivo = User::factory()->inactive()->invited()->create();

        $this->post(route('users.invitation', $inactivo), ['current_password' => 'password'])->assertForbidden();
        $this->post(route('users.invitation', $this->admin), ['current_password' => 'password'])->assertForbidden();

        Notification::assertNothingSent();
    });

    it('limita los reenvíos por minuto', function () {
        $user = User::factory()->invited()->create();

        foreach (range(1, config('rate_limits.invitation')) as $intento) {
            $this->post(route('users.invitation', $user), ['current_password' => 'password'])->assertRedirect();
        }

        $this->post(route('users.invitation', $user), ['current_password' => 'password'])->assertTooManyRequests();
    });

    it('no envía el correo de recuperación genérico, sino el de bienvenida', function () {
        $user = User::factory()->invited()->create();

        $this->post(route('users.invitation', $user), ['current_password' => 'password']);

        Notification::assertSentTo($user, WelcomeNotification::class);
        Notification::assertNotSentTo($user, ResetPassword::class);
    });
});

describe('aceptación de la invitación', function () {
    /**
     * @return array<string, mixed>
     */
    $datosDelEnlace = fn (User $user, string $token, array $extra = []): array => [
        'token' => $token,
        'email' => $user->email,
        'password' => 'clave-nueva-123',
        'password_confirmation' => 'clave-nueva-123',
        ...$extra,
    ];

    it('se marca como aceptada al definir la contraseña con el enlace, y se avisa', function () use ($datosDelEnlace) {
        $user = User::factory()->invited()->create();

        $this->post(route('password.store'), $datosDelEnlace($user, Password::createToken($user), ['invitation' => 1]))
            ->assertRedirect(route('login'))
            ->assertSessionHas('success', __('passwords.invitation_accepted'));

        expect($user->fresh()->invitation_accepted_at)->not->toBeNull()
            ->and($user->fresh()->hasPendingInvitation())->toBeFalse();
    });

    it('también se acepta si la persona usa «¿Olvidaste tu contraseña?»', function () use ($datosDelEnlace) {
        $user = User::factory()->invited()->create();

        $this->post(route('password.store'), $datosDelEnlace($user, Password::createToken($user)))
            ->assertSessionHas('success', __('passwords.invitation_accepted'));

        expect($user->fresh()->hasPendingInvitation())->toBeFalse();
    });

    it('permite entrar con la contraseña definida', function () use ($datosDelEnlace) {
        $user = User::factory()->invited()->create();
        $this->post(route('password.store'), $datosDelEnlace($user, Password::createToken($user)));

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'clave-nueva-123']);

        $this->assertAuthenticatedAs($user->fresh());
    });

    it('no toca la marca en una recuperación de contraseña normal', function () use ($datosDelEnlace) {
        $user = User::factory()->create();

        $this->post(route('password.store'), $datosDelEnlace($user, Password::createToken($user)))
            ->assertSessionHas('success', __('passwords.reset'));

        expect($user->fresh())->invited_at->toBeNull()->invitation_accepted_at->toBeNull();
    });

    it('no vuelve a marcar una invitación ya aceptada', function () use ($datosDelEnlace) {
        $antes = now()->subDays(2)->startOfSecond();
        $user = User::factory()->invited()->create(['invitation_accepted_at' => $antes]);

        $this->post(route('password.store'), $datosDelEnlace($user, Password::createToken($user)))
            ->assertSessionHas('success', __('passwords.reset'));

        expect($user->fresh()->invitation_accepted_at->getTimestamp())->toBe($antes->getTimestamp());
    });

    it('deja de permitir el reenvío una vez aceptada', function () use ($datosDelEnlace) {
        $user = User::factory()->invited()->create();
        $this->post(route('password.store'), $datosDelEnlace($user, Password::createToken($user)));

        $this->actingAs(userWithRole(RoleName::Admin))
            ->post(route('users.invitation', $user), ['current_password' => 'password'])
            ->assertForbidden();
    });

    it('explica qué hacer cuando el enlace de invitación venció o ya se usó', function () use ($datosDelEnlace) {
        $user = User::factory()->invited()->create();

        $this->post(route('password.store'), $datosDelEnlace($user, 'token-vencido', ['invitation' => 1]))
            ->assertSessionHasErrors(['email' => __('passwords.invitation_token')]);

        $this->post(route('password.store'), $datosDelEnlace($user, 'token-vencido'))
            ->assertSessionHasErrors(['email' => __('passwords.token')]);

        expect($user->fresh()->hasPendingInvitation())->toBeTrue();
    });
});

describe('pantalla de definir la contraseña', function () {
    it('usa los textos de bienvenida cuando llega desde la invitación', function () {
        $this->get(route('password.reset', ['token' => 'abc', 'email' => 'ana@example.com', 'invitation' => 1]))
            ->assertOk()
            ->assertSee('Define tu contraseña')
            ->assertSee('Guardar y continuar')
            ->assertSee('name="invitation" value="1"', false)
            ->assertDontSee('Restablecer contraseña');
    });

    it('usa los textos habituales en una recuperación normal', function () {
        $this->get(route('password.reset', ['token' => 'abc', 'email' => 'ana@example.com']))
            ->assertOk()
            ->assertSee('Restablecer contraseña')
            ->assertSee('Guardar contraseña')
            ->assertDontSee('Define tu contraseña')
            ->assertDontSee('name="invitation"', false);
    });
});

describe('correo de bienvenida', function () {
    it('explica qué hacer y cuándo caduca, y lleva el enlace de invitación', function () {
        $user = User::factory()->create(['name' => 'Ana Pérez', 'email' => 'ana@example.com']);
        $minutos = config('auth.passwords.users.expire');

        $correo = (new WelcomeNotification('token-de-prueba'))->toMail($user);

        expect($correo->subject)->toContain('define tu contraseña')
            ->and($correo->greeting)->toBe('Hola, Ana Pérez')
            ->and($correo->actionText)->toBe('Definir mi contraseña')
            ->and($correo->actionUrl)->toContain('token-de-prueba')->toContain('invitation=1')->toContain('ana%40example.com')
            ->and(implode(' ', $correo->introLines))->toContain('ana@example.com')
            ->and(implode(' ', $correo->outroLines))->toContain("caduca en {$minutos} minutos")
            ->toContain('pide a quien te invitó')
            ->toContain('te la pedirá por correo');
    });
});
