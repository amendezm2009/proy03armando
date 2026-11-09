@extends('layouts.app')

@section('title', 'Clasificaciones')

@section('content')
    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-tags me-1"></i> Clasificaciones</span>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('classifications.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i> Nueva
                </a>
                <a href="{{ route('classifications.export') }}" class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i> Exportar Excel
                </a>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal"
                    data-bs-target="#importModal">
                    <i class="bi bi-upload me-1"></i> Importar
                </button>
            </div>
        </div>

        <div class="card-body">
            <table id="classifications-table" class="table table-hover align-middle w-100">
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
                    @foreach ($classifications as $classification)
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
                            <td data-order="{{ $classification->created_at->timestamp }}">
                                {{ $classification->created_at->format('d/m/Y H:i') }}
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('classifications.edit', $classification) }}"
                                    class="btn btn-outline-primary btn-sm" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" action="{{ route('classifications.destroy', $classification) }}"
                                    class="d-inline js-delete-form">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

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
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            new DataTable('#classifications-table', {
                pageLength: 10,
                order: [
                    [4, 'desc']
                ],
                columnDefs: [{
                    orderable: false,
                    targets: 5
                }],
                language: {
                    search: 'Buscar:',
                    lengthMenu: 'Mostrar _MENU_ registros',
                    info: 'Mostrando _START_ a _END_ de _TOTAL_',
                    infoEmpty: 'Sin registros',
                    zeroRecords: 'No se encontraron resultados',
                    paginate: {
                        first: 'Primero',
                        last: 'Último',
                        next: 'Siguiente',
                        previous: 'Anterior'
                    },
                },
            });
            
            @if ($errors->has('file'))
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
