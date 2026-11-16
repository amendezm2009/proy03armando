@extends('layouts.app')

@section('title', 'Bienvenido al Sistema de Gestión')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <!-- Hero Card -->
            <div class="card border-0 shadow-sm mb-4 overflow-hidden">
                <div class="card-body p-5 bg-white">
                    <h1 class="display-6 fw-bold text-dark mb-3">Sistema de Gestión de Productos</h1>
                    <p class="lead text-secondary mb-4">
                        Plataforma desarrollada con <strong>Laravel 13</strong>, <strong>Bootstrap 5</strong>,
                        <strong>jQuery</strong> y <strong>DataTables</strong>. Diseñada para soportar catálogos paginados,
                        control de accesos RBAC y exportaciones en Excel y PDF.
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        @if (auth()->guest() || auth()->user()->can(App\PermissionName::ViewClassifications->value))
                            <a href="{{ route('classifications.index') }}" class="btn btn-primary btn-lg px-4 shadow-sm">
                                <i class="bi bi-tags-fill me-2"></i> Ir a Clasificaciones
                            </a>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Cards -->
            <!-- <div class="row g-4 mb-4" id="tech-stack">
                                            <div class="col-md-3">
                                                <div class="card h-100 border-0 shadow-sm">
                                                    <div class="card-body text-center p-4">
                                                        <div class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center mb-3" style="width: 56px; height: 56px;">
                                                            <i class="bi bi-bootstrap-fill fs-3"></i>
                                                        </div>
                                                        <h5 class="fw-bold mb-1">Bootstrap 5</h5>
                                                        <p class="text-muted small mb-0">Sistema de rejilla responsivo, componentes y utilidades visuales cargadas vía Vite.</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div> -->

        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof window.jQuery !== 'undefined') {
                console.log('jQuery disponible');
            } else {
                console.error('jQuery no está disponible en window.$');
            }
        });
    </script>
@endpush