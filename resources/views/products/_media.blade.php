{{--
    Galería de fotos y videos de un producto: tarjeta, ventana de ampliación y su script. La usan la ficha interna
    (products.show, con controles de gestión si hay permiso) y el catálogo público (catalog.show, solo lectura).

    USO: @include('products._media')                          → gestión si la persona puede editar productos
         @include('products._media', ['canManage' => false])  → solo lectura (catálogo público)
    Requiere $product con la relación "media" cargada.
--}}
            @php
                // Quien incluye el parcial puede forzarlo (el catálogo público pasa false); si no, depende del permiso.
                $canManage ??= auth()->user()?->can(App\PermissionName::UpdateProducts->value) ?? false;
                $maxFiles = (int) config('catalog.media.max_files');
                $remaining = $maxFiles - $product->media->count();
                $uploadErrors = collect($errors->get('media'))->merge(collect($errors->get('media.*'))->flatten())->unique();
            @endphp

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-images me-1"></i> Fotos y videos</span>
                    @if ($canManage)
                        <span class="badge bg-secondary-subtle text-secondary border">{{ $product->media->count() }} de {{ $maxFiles }}</span>
                    @endif
                </div>

                <div class="card-body">
                    @foreach ($uploadErrors->merge($errors->get('ids')) as $message)
                        <div class="text-danger small mb-2" role="alert"><i class="bi bi-exclamation-circle me-1"></i>{{ $message }}</div>
                    @endforeach

                    @if ($product->media->isEmpty())
                        <p class="text-muted mb-0">Este producto aún no tiene fotos ni videos.</p>
                    @endif

                    <div class="row g-3">
                        @foreach ($product->media as $media)
                            @php($isCover = $product->cover_media_id === $media->id)
                            <div class="col-6 col-md-4">
                                <div class="border rounded overflow-hidden h-100 {{ $isCover ? 'border-primary border-2' : '' }}">
                                    <button type="button" class="btn p-0 border-0 w-100 position-relative d-block" data-media-open
                                        data-type="{{ $media->type }}" data-src="{{ $media->url() }}"
                                        data-title="{{ $media->original_name }}"
                                        title="{{ $media->isVideo() ? 'Reproducir' : 'Ampliar' }}: {{ $media->original_name }}">
                                        @if ($media->isImage())
                                            <img src="{{ $media->thumbnailUrl() }}" alt="{{ $media->original_name }}"
                                                class="w-100 object-fit-cover d-block" style="aspect-ratio: 1 / 1" loading="lazy">
                                        @else
                                            {{-- El primer fotograma lo muestra el propio navegador (sin ffmpeg): "preload=metadata" y
                                                 "#t=0.1" lo posicionan ahí sin descargar el video entero. --}}
                                            <div class="bg-dark position-relative" style="aspect-ratio: 1 / 1">
                                                <video src="{{ $media->url() }}#t=0.1" preload="metadata" muted playsinline
                                                    tabindex="-1" aria-hidden="true"
                                                    class="w-100 h-100 object-fit-cover d-block" style="pointer-events: none"></video>
                                                <span class="position-absolute top-50 start-50 translate-middle text-white fs-1 lh-1"
                                                    style="text-shadow: 0 0 .5rem rgba(0, 0, 0, .6)">
                                                    <i class="bi bi-play-circle-fill"></i>
                                                </span>
                                                {{-- "top: auto": Bootstrap da `top: -1px` a todo .badge dentro de un .btn y, junto con
                                                     bottom-0, la etiqueta se estiraba de arriba abajo. --}}
                                                <span class="badge bg-dark position-absolute bottom-0 end-0 m-1"
                                                    style="top: auto">{{ $media->durationLabel() }}</span>
                                            </div>
                                        @endif
                                        @if ($isCover)
                                            <span class="badge bg-primary position-absolute top-0 start-0 m-1">Portada</span>
                                        @endif
                                    </button>

                                    @if ($canManage)
                                        <div class="d-flex justify-content-center gap-1 p-2">
                                            @foreach (['up' => ['arrow-left', 'Mover antes', $loop->first], 'down' => ['arrow-right', 'Mover después', $loop->last]] as $direction => [$icon, $label, $disabled])
                                                <form method="POST" action="{{ route('products.media.move', [$product, $media]) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="direction" value="{{ $direction }}">
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm"
                                                        title="{{ $label }}" @disabled($disabled)>
                                                        <i class="bi bi-{{ $icon }}"></i>
                                                    </button>
                                                </form>
                                            @endforeach
                                            @if ($media->isImage() && ! $isCover)
                                                <form method="POST" action="{{ route('products.media.cover', [$product, $media]) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="btn btn-outline-primary btn-sm" title="Usar como portada">
                                                        <i class="bi bi-star"></i>
                                                    </button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('products.media.destroy', [$product, $media]) }}"
                                                class="js-delete-media">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if ($canManage)
                        @if ($remaining > 0)
                            <form method="POST" action="{{ route('products.media.store', $product) }}" enctype="multipart/form-data"
                                class="mt-3">
                                @csrf
                                <label for="media" class="form-label">Agregar fotos o videos</label>
                                <div class="input-group">
                                    <input type="file" id="media" name="media[]" multiple required
                                        accept="image/jpeg,image/png,image/webp,video/mp4,video/webm"
                                        class="form-control @if ($uploadErrors->isNotEmpty()) is-invalid @endif">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-upload me-1"></i> Subir
                                    </button>
                                </div>
                                <div class="form-text">
                                    Puedes agregar {{ $remaining }} más (máximo {{ $maxFiles }} por producto).
                                    Fotos JPG, PNG o WebP de hasta {{ round(config('catalog.media.image_max_kb') / 1024) }} MB;
                                    videos MP4 o WebM de menos de {{ round(config('catalog.media.video_max_bytes') / 1048576) }} MB
                                    y máximo {{ intdiv(config('catalog.media.video_max_seconds'), 60) }} minutos.
                                    La portada debe ser una foto.
                                </div>
                            </form>
                        @else
                            <p class="text-muted small mt-3 mb-0">
                                Llegaste al máximo de {{ $maxFiles }} archivos. Elimina alguno para agregar otro.
                            </p>
                        @endif
                    @endif
                </div>
            </div>

    <div class="modal fade" id="mediaModal" tabindex="-1" aria-labelledby="mediaModalTitle" aria-hidden="true">
        {{-- La ventana toma el tamaño del recurso (hasta casi toda la pantalla) en lugar de un ancho fijo. --}}
        <div class="modal-dialog modal-dialog-centered" style="max-width: 96vw; width: fit-content">
            <div class="modal-content bg-dark text-white">
                <div class="modal-header border-secondary py-2">
                    <h5 class="modal-title text-truncate" id="mediaModalTitle" style="max-width: min(60vw, 40rem)"></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-0 d-flex justify-content-center" id="mediaModalBody"></div>
            </div>
        </div>
    </div>

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modalElement = document.getElementById('mediaModal');
            const body = document.getElementById('mediaModalBody');
            const title = document.getElementById('mediaModalTitle');

            document.querySelectorAll('[data-media-open]').forEach(function(button) {
                button.addEventListener('click', function() {
                    const isVideo = button.dataset.type === 'video';
                    const element = document.createElement(isVideo ? 'video' : 'img');

                    element.src = button.dataset.src;
                    // Se ve completo, sin recortar ni desplazarse: nunca más grande que la pantalla.
                    element.style.display = 'block';
                    element.style.maxWidth = '92vw';
                    element.style.maxHeight = 'calc(100vh - 8rem)';
                    // Un recurso pequeño no queda diminuto; "contain" evita deformarlo si no cabe a lo alto.
                    element.style.minWidth = 'min(92vw, 480px)';
                    element.style.objectFit = 'contain';

                    if (isVideo) {
                        element.controls = true;
                        element.autoplay = true;
                        element.preload = 'metadata';
                    } else {
                        element.alt = button.dataset.title;
                    }

                    title.textContent = button.dataset.title;
                    body.replaceChildren(element);
                    bootstrap.Modal.getOrCreateInstance(modalElement).show();
                });
            });

            // Al cerrar se vacía la ventana: así un video deja de reproducirse y de descargarse.
            modalElement.addEventListener('hidden.bs.modal', function() {
                body.replaceChildren();
            });

            document.querySelectorAll('.js-delete-media').forEach(function(form) {
                form.addEventListener('submit', function(event) {
                    if (!confirm('¿Eliminar este archivo?')) {
                        event.preventDefault();
                    }
                });
            });
        });
    </script>
@endpush
