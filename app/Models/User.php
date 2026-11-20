<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\RoleName;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/*
 * is_active, avatar_path, invited_at e invitation_accepted_at no están en Fillable a propósito:
 * no se pueden asignar en masa desde un formulario o una petición; solo las acciones de
 * App\Actions y el restablecimiento de contraseña los modifican.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Valores por defecto de un usuario nuevo en memoria (la base de datos ya los pone al guardar,
     * pero el modelo recién creado no los trae): sin esto is_active sería null y la cuenta pasaría
     * por desactivada hasta recargarla.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'invited_at' => 'datetime',
            'invitation_accepted_at' => 'datetime',
        ];
    }

    /**
     * Clasificaciones que la persona usó hace poco al filtrar o editar productos (las más recientes
     * primero con orderByPivot('last_used_at', 'desc')). Las escribe App\Actions\RecordClassificationSearches.
     *
     * @return BelongsToMany<Classification, $this>
     */
    public function recentClassifications(): BelongsToMany
    {
        return $this->belongsToMany(Classification::class, 'classification_users_searches')->withPivot('last_used_at');
    }

    /**
     * Nombre de la ruta de su panel según el rol: el administrador va a admin.dashboard, el cliente a
     * client.dashboard y quien no tiene rol se queda en la página de inicio.
     */
    public function homeRoute(): string
    {
        return match (true) {
            $this->hasRole(RoleName::Admin->value) => 'admin.dashboard',
            $this->hasRole(RoleName::Cliente->value) => 'client.dashboard',
            default => 'home',
        };
    }

    public function hasAvatar(): bool
    {
        return $this->avatar_path !== null;
    }

    /**
     * Dirección pública de la foto de perfil (la sirve AvatarFileController), o null si no tiene.
     */
    public function avatarUrl(): ?string
    {
        return $this->hasAvatar() ? route('avatars.show', basename($this->avatar_path)) : null;
    }

    /**
     * Dos letras para el avatar cuando no hay foto: las iniciales de las dos primeras palabras del
     * nombre o, si solo hay una, sus dos primeras letras. Ignora símbolos y signos.
     */
    public function initials(): string
    {
        $words = array_values(array_filter(
            array_map(fn (string $word): string => preg_replace('/[^\p{L}\p{N}]/u', '', $word), preg_split('/\s+/u', trim($this->name ?? '')) ?: []),
            fn (string $word): bool => $word !== '',
        ));

        $initials = count($words) >= 2
            ? mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1)
            : mb_substr($words[0] ?? '', 0, 2);

        return mb_strtoupper($initials) ?: '?';
    }

    /**
     * ¿Se le invitó y aún no define su contraseña con el enlace?
     */
    public function hasPendingInvitation(): bool
    {
        return $this->invited_at !== null && $this->invitation_accepted_at === null;
    }

    /**
     * Filtros del listado de usuarios: search (nombre o correo), role e is_active.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(
                fn (Builder $query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
            ))
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->role($role))
            ->when(
                isset($filters['is_active']),
                fn (Builder $query) => $query->where('is_active', (bool) $filters['is_active'])
            );
    }

    /**
     * Quienes tienen el rol admin y la cuenta activa.
     */
    public function scopeActiveAdmins(Builder $query): void
    {
        $query->role(RoleName::Admin->value)->where('is_active', true);
    }

    /**
     * ¿Es la única persona administradora con la cuenta activa?
     */
    public function isLastActiveAdmin(): bool
    {
        return $this->is_active
            && $this->hasRole(RoleName::Admin->value)
            && ! static::activeAdmins()->whereKeyNot($this->getKey())->exists();
    }
}
