<?php

namespace App\Providers;

use App\Actions\ConfirmPassword;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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

        // Un limitador por cada clave de config/rate_limits.php. La API cuenta por usuario
        // (o por IP si no hay token); las rutas de acceso cuentan siempre por IP.
        foreach (array_keys(config('rate_limits')) as $name) {
            RateLimiter::for($name, fn (Request $request): Limit => Limit::perMinute(config("rate_limits.{$name}"))
                ->by($name === 'api' ? ($request->user()?->id ?: $request->ip()) : $request->ip()));
        }
    }
}
