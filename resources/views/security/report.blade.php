@extends('layouts.app')

@section('title', 'Seguridad')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card">
                <div class="card-header"><i class="bi bi-shield-lock me-1"></i> Seguridad de la configuración</div>

                <div class="card-body">
                    @isset($rows)
                        @php
                            $failures = $rows->where('status', 'FALLA')->count();
                            $warnings = $rows->where('status', 'AVISO')->count();
                            $badge = ['OK' => 'success', 'AVISO' => 'warning', 'FALLA' => 'danger'];
                        @endphp

                        <p class="text-muted small">
                            Entorno: <strong>{{ app()->environment() }}</strong>
                            @if ($strict) · evaluado como producción @endif
                            · Solo se muestra si cada ajuste está bien puesto, nunca su valor.
                        </p>

                        @if ($failures > 0)
                            <div class="alert alert-danger" role="alert">{{ $failures }} revisión(es) graves fallan en producción.</div>
                        @elseif ($warnings > 0)
                            <div class="alert alert-warning" role="alert">Sin fallas. {{ $warnings }} aviso(s) por revisar antes de publicar.</div>
                        @else
                            <div class="alert alert-success" role="alert">Todo en orden.</div>
                        @endif

                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr><th style="width: 6rem">Estado</th><th>Revisión</th><th>Cómo corregirlo</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        <tr>
                                            <td><span class="badge text-bg-{{ $badge[$row['status']] }}">{{ $row['status'] }}</span></td>
                                            <td>{{ $row['label'] }}</td>
                                            <td class="text-muted">{{ $row['fix'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <a href="{{ route('security.report') }}" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-lock me-1"></i> Cerrar informe
                        </a>
                    @else
                        <p class="text-muted">
                            Este informe es de solo lectura. Por seguridad pide tu contraseña cada vez que lo abres.
                        </p>

                        <form method="POST" action="{{ route('security.report.show') }}" class="needs-validation" novalidate>
                            @csrf
                            <div class="mb-3">
                                <x-form.password name="current_password" label="Tu contraseña" required />
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="strict" value="1" id="strict">
                                <label class="form-check-label" for="strict">
                                    Evaluar como producción (muestra qué fallaría al desplegar)
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-unlock me-1"></i> Ver informe
                            </button>
                        </form>
                    @endisset
                </div>
            </div>
        </div>
    </div>
@endsection
