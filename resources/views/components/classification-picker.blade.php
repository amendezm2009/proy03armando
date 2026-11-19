{{--
    Selector de clasificaciones con búsqueda en el servidor (Tom Select, ver resources/js/classification-picker.js).
    No carga todas las clasificaciones: pide la lista inicial al abrir la página y al enfocarlo vacío muestra
    las recientes del usuario y las primeras por nombre (sin filtrar mientras haya menos de
    ClassificationLookupRequest::MIN_CHARS letras) y busca en el
    servidor cuando se escriben esas letras o más. Elegir una clasificación la anota en el historial.

    USO:
      <x-classification-picker id="classifications" name="classifications[]" :selected="$selectedClassifications" />
      <x-classification-picker id="classification_ids" name="classification_ids[]" :selected="$selected" only-active />

    - selected: colección de Classification ya elegidas (id, name, color_badge) para pintarlas al cargar la página.
    - only-active: la búsqueda omite las clasificaciones inactivas (formularios de alta y edición).
--}}
@props(['id', 'name', 'selected' => collect(), 'onlyActive' => false, 'label' => 'Clasificaciones'])

<select id="{{ $id }}" name="{{ $name }}" multiple data-classification-picker
    data-url="{{ route('classifications.lookup') }}" data-only-active="{{ $onlyActive ? '1' : '0' }}"
    data-recent-url="{{ route('classifications.recent.destroy', ['classification' => '__ID__']) }}"
    data-min-chars="{{ App\Http\Requests\ClassificationLookupRequest::MIN_CHARS }}"
    placeholder="Escribe {{ App\Http\Requests\ClassificationLookupRequest::MIN_CHARS }} letras para buscar…"
    aria-label="{{ $label }}" {{ $attributes->class(['form-select']) }}>
    @foreach ($selected as $classification)
        <option value="{{ $classification->id }}" selected
            data-data="{{ json_encode(['color' => $classification->color_badge, 'optgroup' => 'other']) }}">{{ $classification->name }}</option>
    @endforeach
</select>
