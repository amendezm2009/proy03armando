{{--
    Foto de perfil con su editor: el avatar grande con una insignia de lápiz en la esquina superior
    derecha que abre una ventana para elegir una imagen o tomar una foto, moverla y acercarla dentro de
    un recorte con vista previa redonda, y guardarla. Solo para el perfil propio: <x-avatar-editor :user="$user" />

    Toda la interacción está en resources/js/avatar-editor.js (cropperjs); el recorte se envía como un
    archivo normal al formulario de abajo (route profile.avatar.update). El servidor valida y vuelve a
    codificar la imagen (App\Actions\SaveAvatar): lo del navegador es solo comodidad.
--}}
@props(['user'])

<div data-avatar-editor class="d-flex align-items-center gap-3 mb-4">
    <div class="position-relative">
        <x-avatar :user="$user" :size="112" :decorative="false" />

        <button type="button" data-bs-toggle="modal" data-bs-target="#avatarModal" title="Cambiar foto de perfil"
            aria-label="Cambiar foto de perfil"
            class="btn btn-primary rounded-circle position-absolute top-0 end-0 d-flex align-items-center justify-content-center p-0 border border-2 border-white shadow-sm"
            style="width: 34px; height: 34px;">
            <i class="bi bi-pencil-fill small"></i>
        </button>
    </div>

    <div>
        <div class="fw-semibold">{{ $user->name }}</div>
        <div class="text-muted small">JPG, PNG o WebP de hasta 2 MB. Puedes moverla y acercarla antes de guardar.</div>
    </div>

    {{-- El recorte se asigna a este campo y el formulario se envía --}}
    <form method="POST" action="{{ route('profile.avatar.update') }}" enctype="multipart/form-data" data-avatar-form
        class="d-none">
        @csrf
        <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-avatar-output>
    </form>

    <div class="modal fade" id="avatarModal" tabindex="-1" aria-labelledby="avatarModalLabel" aria-hidden="true"
        data-bs-backdrop="static" data-avatar-modal>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="avatarModalLabel"><i class="bi bi-person-bounding-box me-1"></i> Foto de perfil
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-danger py-2 small" role="alert" data-avatar-error hidden></div>

                    {{-- Paso 1: elegir de dónde sale la foto --}}
                    <div data-step="choose">
                        <p class="text-muted mb-3">Elige cómo quieres poner tu foto.</p>

                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-primary" data-avatar-pick>
                                <i class="bi bi-image me-1"></i> Elegir una imagen
                            </button>
                            <button type="button" class="btn btn-outline-primary" data-avatar-camera-open hidden>
                                <i class="bi bi-camera me-1"></i> Tomar una foto
                            </button>
                        </div>

                        <input type="file" class="d-none" accept="image/jpeg,image/png,image/webp" data-avatar-file>

                        <p class="small text-muted mt-3 mb-0" data-avatar-camera-note hidden>
                            Tomar una foto no está disponible en este navegador o conexión (hace falta HTTPS).
                        </p>

                        @if ($user->hasAvatar())
                            <hr>
                            <form method="POST" action="{{ route('profile.avatar.destroy') }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                                    <i class="bi bi-trash me-1"></i> Quitar mi foto y usar mis iniciales
                                </button>
                            </form>
                        @endif
                    </div>

                    {{-- Paso 2 (opcional): cámara --}}
                    <div data-step="camera" hidden>
                        <video class="w-100 rounded bg-dark" autoplay playsinline muted data-avatar-video
                            style="max-height: 320px; object-fit: cover;"></video>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-outline-secondary" data-avatar-back>Volver</button>
                            <button type="button" class="btn btn-primary flex-fill" data-avatar-capture>
                                <i class="bi bi-camera-fill me-1"></i> Capturar
                            </button>
                        </div>
                    </div>

                    {{-- Paso 3: mover y acercar --}}
                    <div data-step="crop" hidden>
                        <div class="row g-3 align-items-center">
                            <div class="col-sm-8">
                                <div data-avatar-crop class="rounded overflow-hidden"></div>
                            </div>
                            <div class="col-sm-4 text-center">
                                <p class="small text-muted mb-2">Así se verá</p>
                                <cropper-viewer selection="#avatarSelection"
                                    style="display: inline-block; width: 96px; height: 96px; border-radius: 50%; overflow: hidden;"></cropper-viewer>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-2 mt-3">
                            <span class="small text-muted me-auto">Arrastra para mover; usa la rueda o los botones para acercar.</span>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-avatar-zoom-out
                                aria-label="Alejar"><i class="bi bi-zoom-out"></i></button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-avatar-zoom-in
                                aria-label="Acercar"><i class="bi bi-zoom-in"></i></button>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-outline-secondary" data-avatar-back>Volver</button>
                            <button type="button" class="btn btn-primary flex-fill" data-avatar-save>
                                <i class="bi bi-check-lg me-1"></i> Guardar foto
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
