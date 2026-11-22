@php
    $user = $user ?? null;
    $currentRole = old('role', $user?->getRoleNames()->first() ?? App\RoleName::Cliente->value);
    // Nadie cambia su propio rol: se muestra, pero no se puede modificar (el servidor también lo impide).
    $isSelf = $user !== null && $user->is(auth()->user());
@endphp

@csrf

<p class="text-muted small mb-3"><span class="text-danger">*</span> Campo obligatorio</p>

<div class="row g-3">
    <div class="col-md-6">
        <x-form.input name="name" label="Nombre" :value="$user?->name" required maxlength="255" autocomplete="off"
            hint="Ingresa el nombre." />
    </div>

    <div class="col-md-6">
        <x-form.input name="email" type="email" label="Correo electrónico" :value="$user?->email" required
            maxlength="255" autocomplete="off" hint="Ingresa un correo válido." />
    </div>

    <div class="col-md-6">
        <x-form.label for="role" required>Rol</x-form.label>
        <select id="role" name="role" required @disabled($isSelf)
            class="form-select @error('role') is-invalid @enderror">
            @foreach ($roles as $role)
                <option value="{{ $role->value }}" @selected($currentRole === $role->value)>{{ $role->label() }}</option>
            @endforeach
        </select>
        @if ($isSelf)
            <input type="hidden" name="role" value="{{ $currentRole }}">
        @endif
        <div class="invalid-feedback">@error('role'){{ $message }}@else Elige un rol.@enderror</div>
        @if ($isSelf)
            <div class="form-text">No puedes cambiar tu propio rol.</div>
        @endif
    </div>

    @if ($user === null)
        <div class="col-12">
            <div class="alert alert-info mb-0">
                <i class="bi bi-envelope-check me-1"></i>
                No necesitas definir una contraseña: la persona recibirá un correo con un enlace para crear la suya.
            </div>
        </div>
    @endif
</div>

<div class="d-flex gap-2 mt-4">
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1"></i> {{ $user === null ? 'Crear y enviar invitación' : 'Guardar' }}
    </button>
    <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Cancelar</a>
</div>
