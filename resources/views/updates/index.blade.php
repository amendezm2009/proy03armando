@extends('layouts.app')

@section('title', 'Actualizaciones')

@section('content')
    <div data-updates
        data-upload-url="{{ route('updates.upload') }}"
        data-inspect-url="{{ route('updates.inspect') }}"
        data-start-url="{{ route('updates.start') }}"
        data-step-url="{{ route('updates.step') }}"
        data-revert-url="{{ route('updates.revert') }}"
        data-discard-url="{{ route('updates.discard', ['version' => '0.0.0']) }}"
        data-status="{{ json_encode($status) }}">

        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header"><i class="bi bi-info-circle me-1"></i> Estado</div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Versión instalada</span><strong>{{ $version }}</strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Build</span><strong>{{ $build }}</strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Instalada desde la web</span>
                            <span class="text-muted">{{ $installedAt ? \Illuminate\Support\Carbon::parse($installedAt)->timezone(config('app.timezone'))->format('d/m/Y H:i') : '—' }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Mantenimiento</span>
                            @if ($maintenance)
                                <span class="badge text-bg-warning">Activo</span>
                            @else
                                <span class="badge text-bg-success">No</span>
                            @endif
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span>PHP</span><span class="text-muted">{{ PHP_VERSION }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header"><i class="bi bi-cloud-arrow-up me-1"></i> 1. Subir el paquete</div>
                    <div class="card-body">
                        <p class="text-muted small mb-2">
                            Selecciona <strong>todos</strong> los archivos de la carpeta <code>releases\vX.Y.Z\</code> que generó
                            <code>scripts\release.ps1</code>: las partes <code>app-vX.Y.Z.zip.001</code>, <code>.002</code>…, más
                            <code>manifest.json</code> y <code>manifest.json.sig</code>. Se suben de una en una.
                        </p>
                        <div class="input-group mb-2">
                            <input type="file" class="form-control" multiple data-upload-input aria-label="Archivos del paquete">
                            <button type="button" class="btn btn-primary" data-upload-button>
                                <i class="bi bi-upload me-1"></i> Subir
                            </button>
                        </div>
                        <ul class="list-unstyled small mb-0" data-upload-list></ul>
                        <div class="alert alert-danger py-2 mt-2 mb-0" role="alert" data-upload-error hidden></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header"><i class="bi bi-box-seam me-1"></i> 2. Paquetes subidos</div>
            <div class="card-body">
                @if (count($packages) === 0)
                    <p class="text-muted mb-0">No hay paquetes subidos.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr><th>Versión</th><th>Partes</th><th class="text-end">Acciones</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($packages as $package)
                                    <tr data-package="{{ $package['version'] }}">
                                        <td><strong>{{ $package['version'] }}</strong></td>
                                        <td>
                                            @if ($package['parts']['expected'] > 0)
                                                {{ $package['parts']['present'] }} de {{ $package['parts']['expected'] }}
                                            @else
                                                <span class="text-muted">falta manifest.json</span>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-inspect="{{ $package['version'] }}">
                                                <i class="bi bi-shield-check me-1"></i> Verificar
                                            </button>
                                            <button type="button" class="btn btn-outline-danger btn-sm" data-discard="{{ $package['version'] }}">
                                                <i class="bi bi-trash me-1"></i> Descartar
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="mt-3" data-report hidden>
                    <h2 class="h6" data-report-title></h2>
                    <div class="alert alert-danger py-2" role="alert" data-report-problems hidden></div>
                    <div class="alert alert-warning py-2" role="alert" data-report-warnings hidden></div>
                    <dl class="row small mb-2" data-report-summary hidden></dl>

                    <div data-install-box hidden>
                        <hr>
                        <h2 class="h6">3. Instalar</h2>
                        <p class="text-muted small">
                            La aplicación pasará a modo de mantenimiento, se respaldará la base de datos, se reemplazará el código y se
                            aplicarán las migraciones. Si algo falla, todo vuelve a como estaba. Esta página sigue disponible mientras tanto.
                        </p>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <x-form.password name="current_password" label="Tu contraseña" />
                            </div>
                            <div class="col-md-7">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="update-ack" data-install-ack>
                                    <label class="form-check-label small" for="update-ack">
                                        Entiendo que la aplicación quedará en mantenimiento unos minutos.
                                    </label>
                                </div>
                                <button type="button" class="btn btn-primary" data-install-button>
                                    <i class="bi bi-rocket-takeoff me-1"></i> Instalar la versión <span data-install-version></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-3" data-progress hidden>
            <div class="card-header"><i class="bi bi-activity me-1"></i> Instalación</div>
            <div class="card-body">
                <p class="fw-semibold mb-2" data-progress-label></p>
                <div class="progress mb-3" role="progressbar" aria-label="Avance de la instalación" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%" data-progress-bar></div>
                </div>
                <div class="alert alert-danger py-2" role="alert" data-progress-error hidden></div>
                <pre class="small bg-body-tertiary border rounded p-2 mb-3" style="max-height: 14rem; overflow: auto" data-progress-log></pre>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" data-resume hidden>
                        <i class="bi bi-play-fill me-1"></i> Reanudar
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm" data-revert hidden>
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Revertir
                    </button>
                    <button type="button" class="btn btn-success btn-sm" data-reload hidden>
                        <i class="bi bi-arrow-clockwise me-1"></i> Terminar y recargar
                    </button>
                </div>
            </div>
        </div>

        <div class="row g-3 mt-0">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><i class="bi bi-clock-history me-1"></i> Historial</div>
                    <div class="card-body">
                        @forelse ($history as $line)
                            <div class="small text-muted font-monospace">{{ $line }}</div>
                        @empty
                            <p class="text-muted mb-0">Aún no se instaló nada desde la web.</p>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><i class="bi bi-database me-1"></i> Respaldos de la base de datos</div>
                    <div class="card-body">
                        @forelse ($backups as $backup)
                            <div class="small text-muted font-monospace">{{ $backup['file'] }}</div>
                        @empty
                            <p class="text-muted mb-0">Todavía no hay respaldos. Se crea uno antes de cada instalación.</p>
                        @endforelse
                        <p class="small text-muted mt-2 mb-0">Para restaurar uno: <code>php artisan db:restore</code>.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
