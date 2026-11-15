<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Closure;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Inicia la sesión web.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->attempt(fn (): bool => Auth::attempt($this->only('email', 'password'), $this->boolean('remember')));
    }

    /**
     * Comprueba las credenciales sin iniciar sesión (API con token).
     *
     * @throws ValidationException
     */
    public function validatedUser(): User
    {
        $this->attempt(fn (): bool => Auth::validate($this->only('email', 'password')));

        return User::where('email', $this->string('email')->value())->firstOrFail();
    }

    /**
     * @param  Closure(): bool  $credentialsAreValid
     *
     * @throws ValidationException
     */
    private function attempt(Closure $credentialsAreValid): void
    {
        $this->ensureIsNotRateLimited();

        if (! $credentialsAreValid()) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', ['seconds' => $seconds]),
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')->value()).'|'.$this->ip());
    }
}
