{{--
    Selector "Mostrar N" del tamaño de página de una lista paginada. Cada opción es una dirección: la actual con
    el nuevo tamaño y la lista de vuelta en su primera página; los demás filtros y páginas se conservan. Opciones:
    config('catalog.page_sizes').

    USO: <x-per-page-select name="attention_per_page" :paginator="$stats['attention']" anchor="atencion" />
      - name: parámetro de la dirección que guarda el tamaño (per_page, attention_per_page, ...).
      - paginator: la lista paginada; de ella salen el tamaño actual y el nombre de su parámetro de página.
      - anchor: (opcional) id de la sección a la que se salta al cambiar el tamaño.
--}}
@props(['name', 'paginator', 'anchor' => null])

<label class="d-inline-flex align-items-center gap-2 small text-muted mb-0 text-nowrap">
    <span>Mostrar</span>
    {{-- data-navigate: resources/js/app.js navega a la dirección de la opción elegida (sin onchange en línea, que la CSP bloquea). --}}
    <select class="form-select form-select-sm w-auto" aria-label="Elementos por página" data-navigate>
        @foreach (config('catalog.page_sizes') as $size)
            <option value="{{ request()->fullUrlWithQuery([$name => $size, $paginator->getPageName() => null]) }}{{ $anchor ? '#'.$anchor : '' }}"
                @selected($paginator->perPage() === $size)>{{ $size }}</option>
        @endforeach
    </select>
</label>
