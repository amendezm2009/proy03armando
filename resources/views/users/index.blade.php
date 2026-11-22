@extends('layouts.app')

@section('title', 'Usuarios')

@section('content')
    @php
        $estado = (string) ($filters['is_active'] ?? '');
        $rolFiltro = (string) ($filters['role'] ?? '');
        $porPagina = (int) ($filters['per_page'] ?? 10);
    @endphp

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-people me-1"></i> Usuarios</span>
            @can(App\PermissionName::CreateUsers->value)
                <a href="{{ route('users.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i> Nuevo usuario
                </a>
            @endcan
        </div>

        <div class="card-body">
            <form method="GET" action="{{ route('users.index') }}" class="row g-2 mb-3">
                <div class="col-md-4">
                    <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100"
                        class="form-control" placeholder="Buscar por nombre o correo" aria-label="Buscar">
                </div>
                <div class="col-md-2">
                    <select name="role" class="form-select" aria-label="Rol">
                        <option value="">Todos los roles</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}" @selected($rolFiltro === $role->value)>{{ $role->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="is_active" class="form-select" aria-label="Estado">
                        <option value="">Todos los estados</option>
                        <option value="1" @selected($estado === '1')>Activos</option>
                        <option value="0" @selected($estado === '0')>Desactivados</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="per_page" class="form-select" aria-label="Registros por página">
                        @foreach ([5, 10, 25, 50] as $opcion)
                            <option value="{{ $opcion }}" @selected($porPagina === $opcion)>{{ $opcion }} por página
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">
                        <i class="bi bi-funnel me-1"></i> Filtrar
                    </button>
                    <a href="{{ route('users.index') }}" class="btn btn-outline-secondary" title="Limpiar filtros">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Correo</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($users as $user)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <x-avatar :user="$user" :size="36" />
                                        <span>{{ $user->name }}</span>
                                    </div>
                                </td>
                                <td class="text-muted">{{ $user->email }}</td>
                                <td>
                                    @foreach ($user->getRoleNames() as $roleName)
                                        <span class="badge bg-primary-subtle text-primary border">
                                            {{ App\RoleName::tryFrom($roleName)?->label() ?? $roleName }}
                                        </span>
                                    @endforeach
                                </td>
                                <td>
                                    @if ($user->is_active)
                                        <span class="badge bg-success-subtle text-success border">Activo</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary border">Desactivado</span>
                                    @endif
                                    @if ($user->is_active && $user->hasPendingInvitation())
                                        <span class="badge bg-warning-subtle text-warning-emphasis border"
                                            title="Aún no define su contraseña">Invitación pendiente</span>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @can(App\PermissionName::UpdateUsers->value)
                                        <a href="{{ route('users.edit', $user) }}" class="btn btn-outline-primary btn-sm"
                                            title="Editar">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    @endcan

                                    @can(App\PermissionName::UpdateUsers->value)
                                        @can('removeAvatar', $user)
                                            <form method="POST" action="{{ route('users.avatar.destroy', $user) }}"
                                                class="d-inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-outline-secondary btn-sm"
                                                    title="Quitar su foto de perfil">
                                                    <i class="bi bi-image-alt"></i>
                                                </button>
                                            </form>
                                        @endcan
                                    @endcan

                                    @can(App\PermissionName::CreateUsers->value)
                                        @can('invite', $user)
                                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                                data-confirm-action="{{ route('users.invitation', $user) }}"
                                                data-confirm-method="POST" data-confirm-title="Reenviar invitación"
                                                data-confirm-message="Se enviará un correo nuevo a {{ $user->email }} y el enlace anterior dejará de funcionar."
                                                title="Reenviar invitación (el enlace anterior dejará de funcionar)">
                                                <i class="bi bi-envelope"></i>
                                            </button>
                                        @endcan
                                    @endcan

                                    @can(App\PermissionName::DeactivateUsers->value)
                                        @can('deactivate', $user)
                                            <button type="button" class="btn btn-outline-danger btn-sm"
                                                data-confirm-action="{{ route('users.deactivate', $user) }}"
                                                data-confirm-method="PATCH" data-confirm-title="Desactivar usuario"
                                                data-confirm-message="¿Desactivar a {{ $user->name }}? No podrá iniciar sesión y se cerrarán sus sesiones y tokens."
                                                title="Desactivar">
                                                <i class="bi bi-person-slash"></i>
                                            </button>
                                        @endcan
                                        @can('activate', $user)
                                            <button type="button" class="btn btn-outline-success btn-sm"
                                                data-confirm-action="{{ route('users.activate', $user) }}"
                                                data-confirm-method="PATCH" data-confirm-title="Reactivar usuario"
                                                data-confirm-message="¿Reactivar a {{ $user->name }}? Podrá volver a iniciar sesión."
                                                title="Reactivar">
                                                <i class="bi bi-person-check"></i>
                                            </button>
                                        @endcan
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No se encontraron usuarios.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $users->links('pagination::bootstrap-5') }}

            @unless ($users->hasPages())
                <small class="text-muted">Total: {{ $users->total() }}</small>
            @endunless
        </div>
    </div>

    <x-password-confirm-modal />
@endsection
