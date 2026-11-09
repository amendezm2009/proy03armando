@php($classification = $classification ?? null)

@csrf

<p class="text-muted small mb-3"><span class="text-danger">*</span> Campo obligatorio</p>

<div class="row g-3">
    <div class="col-md-8">
        <x-form.label for="name" required>Nombre</x-form.label>
        <input type="text" id="name" name="name" maxlength="100" required
            class="form-control @error('name') is-invalid @enderror"
            value="{{ old('name', $classification?->name) }}">
        <div class="invalid-feedback">@error('name'){{ $message }}@else Ingresa el nombre (máx. 100 caracteres).@enderror</div>
    </div>

    <div class="col-md-4">
        <x-form.label for="color_badge" required>Color de la etiqueta</x-form.label>
        <input type="color" id="color_badge" name="color_badge" required
            class="form-control form-control-color w-100 @error('color_badge') is-invalid @enderror"
            value="{{ old('color_badge', $classification?->color_badge ?? '#6c757d') }}">
        <div class="invalid-feedback">@error('color_badge'){{ $message }}@else Elige un color.@enderror</div>
    </div>

    <div class="col-12">
        <x-form.label for="description">Descripción</x-form.label>
        <textarea id="description" name="description" rows="3" maxlength="1000"
            class="form-control @error('description') is-invalid @enderror">{{ old('description', $classification?->description) }}</textarea>
        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <input type="hidden" name="is_active" value="0">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
                @checked(old('is_active', $classification?->is_active ?? true))>
            <label class="form-check-label" for="is_active">Activa</label>
        </div>
    </div>
</div>

<div class="d-flex gap-2 mt-4">
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1"></i> Guardar
    </button>
    <a href="{{ route('classifications.index') }}" class="btn btn-outline-secondary">Cancelar</a>
</div>
