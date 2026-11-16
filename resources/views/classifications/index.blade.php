@extends('layouts.app')

@section('title', 'Clasificaciones')

@section('content')
    @php
        $estado = (string) ($filters['is_active'] ?? '');
        $porPagina = (int) ($filters['per_page'] ?? 10);
    @endphp

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-tags me-1"></i> Clasificaciones</span>
            <div class="d-flex flex-wrap gap-2">
                @can(App\PermissionName::CreateClassifications->value)
                    <a href="{{ route('classifications.create') }}" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-lg me-1"></i> Nueva
                    </a>
                @endcan
                <a href="{{ route('classifications.export') }}" class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i> Exportar Excel
                </a>
                @can(App\PermissionName::CreateClassifications->value)
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal"
                        data-bs-target="#importModal">
                        <i class="bi bi-upload me-1"></i> Importar
                    </button>
                @endcan
            </div>
        </div>

        <div class="card-body">
            <form method="GET" action="{{ route('classifications.index') }}" class="row g-2 mb-3">
                <div class="col-md-5">
                    <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100"
                        class="form-control" placeholder="Buscar por nombre o slug" aria-label="Buscar">
                </div>
                <div class="col-md-3">
                    <select name="is_active" class="form-select" aria-label="Estado">
                        <option value="">Todos los estados</option>
                        <option value="1" @selected($estado === '1')>Activas</option>
                        <option value="0" @selected($estado === '0')>Inactivas</option>
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
                    <a href="{{ route('classifications.index') }}" class="btn btn-outline-secondary"
                        title="Limpiar filtros">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Etiqueta</th>
                            <th>Slug</th>
                            <th>Descripción</th>
                            <th>Estado</th>
                            <th>Creada</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($classifications as $classification)
                            <tr>
                                <td>
                                    <span class="badge badge-tag text-white"
                                        style="background-color: {{ $classification->color_badge }}">
                                        {{ $classification->name }}
                                    </span>
                                </td>
                                <td class="text-muted">{{ $classification->slug }}</td>
                                <td>{{ Str::limit($classification->description, 60) }}</td>
                                <td>
                                    @if ($classification->is_active)
                                        <span class="badge bg-success-subtle text-success border">Activa</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary border">Inactiva</span>
                                    @endif
                                </td>
                                <td>{{ $classification->created_at->format('d/m/Y H:i') }}</td>
                                <td class="text-end text-nowrap">
                                    @can(App\PermissionName::UpdateClassifications->value)
                                        <a href="{{ route('classifications.edit', $classification) }}"
                                            class="btn btn-outline-primary btn-sm" title="Editar">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    @endcan
                                    @can(App\PermissionName::DeleteClassifications->value)
                                        <form method="POST"
                                            action="{{ route('classifications.destroy', $classification) }}"
                                            class="d-inline js-delete-form">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    No se encontraron clasificaciones.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $classifications->links('pagination::bootstrap-5') }}

            @unless ($classifications->hasPages())
                <small class="text-muted">Total: {{ $classifications->total() }}</small>
            @endunless
        </div>
    </div>

    @can(App\PermissionName::CreateClassifications->value)
    <!-- Modal de importación -->
    <div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('classifications.import') }}" enctype="multipart/form-data"
                class="modal-content needs-validation" novalidate>
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="importModalLabel">Importar desde Excel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">
                        Archivo .xlsx o .csv (máx. 2 MB) con las cabeceras
                        <code>name</code>, <code>description</code>, <code>color_badge</code> (ej. #0d6efd) e
                        <code>is_active</code> (1 o 0). Los nombres existentes se rechazan.
                    </p>
                    <x-form.label for="file" required>Archivo</x-form.label>
                    <input type="file" id="file" name="file" accept=".xlsx,.csv" required
                        class="form-control @error('file') is-invalid @enderror">
                    <div class="invalid-feedback">
                        @error('file')
                            {{ $message }}
                        @else
                            Selecciona un archivo .xlsx o .csv.
                        @enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Importar</button>
                </div>
            </form>
        </div>
    </div>
    @endcan
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            @if ($errors->has('file') && auth()->user()->can(App\PermissionName::CreateClassifications->value))
                bootstrap.Modal.getOrCreateInstance('#importModal').show();
            @endif

            document.querySelectorAll('.js-delete-form').forEach(function(form) {
                form.addEventListener('submit', function(event) {
                    if (!confirm('¿Eliminar esta clasificación?')) {
                        event.preventDefault();
                    }
                });
            });
        });
    </script>
@endpush
