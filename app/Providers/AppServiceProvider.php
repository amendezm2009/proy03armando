<?php

namespace App\Providers;

use App\Actions\ConfirmPassword;
use App\Actions\RecordSecurityEvent;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Iniciar sesión demuestra la identidad: se reinicia el contador de confirmaciones de contraseña.
        Event::listen(Login::class, fn (Login $event) => RateLimiter::clear(ConfirmPassword::key($event->user)));

        // Búsqueda "contiene" (OWASP A03): whereContainsText(['name', 'sku'], $texto). Un % o _ escrito por la persona
        // se busca como carácter literal y no como comodín ("100%" no coincide con todo lo que empiece con "100").
        // "escape '!'" funciona igual en MySQL y SQLite.
        QueryBuilder::macro('whereContainsText', function (array $columns, string $text): QueryBuilder {
            /** @var QueryBuilder $this */
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';

            return $this->where(function (QueryBuilder $query) use ($columns, $pattern): void {
                foreach ($columns as $column) {
                    $query->orWhereRaw($query->getGrammar()->wrap($column)." like ? escape '!'", [$pattern]);
                }
            });
        });

        // Política de contraseñas (OWASP A07) de todos los formularios y la API (Password::defaults()): mínimo 12
        // caracteres con letras y números. En producción además se rechazan las que aparecen en filtraciones
        // públicas (consulta el servicio Have I Been Pwned enviando solo una huella parcial, nunca la contraseña).
        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(12)->letters()->numbers()->uncompromised()
            : Password::min(12)->letters()->numbers());

        // Registro de seguridad (OWASP A09): accesos, cierres de sesión y contraseñas restablecidas.
        Event::listen(Login::class, fn (Login $event) => app(RecordSecurityEvent::class)->handle('auth.login', ['user_id' => $event->user->getAuthIdentifier()]));
        Event::listen(Logout::class, fn (Logout $event) => app(RecordSecurityEvent::class)->handle('auth.logout', ['user_id' => $event->user?->getAuthIdentifier()]));
        Event::listen(PasswordReset::class, fn (PasswordReset $event) => app(RecordSecurityEvent::class)->handle('auth.password_reset', ['user_id' => $event->user->getAuthIdentifier()]));

        // Un limitador por cada clave de config/rate_limits.php. La API cuenta por usuario
        // (o por IP si no hay token); las rutas de acceso cuentan siempre por IP.
        foreach (array_keys(config('rate_limits')) as $name) {
            RateLimiter::for($name, fn (Request $request): Limit => Limit::perMinute(config("rate_limits.{$name}"))
                ->by($name === 'api' ? ($request->user()?->id ?: $request->ip()) : $request->ip()));
        }
    }
}
