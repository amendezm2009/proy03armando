@php($classification = $classification ?? null)

@csrf

<div class="row g-3">
    <div class="col-md-8">
        <label for="name" class="form-label fw-semibold">Nombre</label>
        <input type="text" id="name" name="name" maxlength="100" required
            class="form-control @error('name') is-invalid @enderror"
            value="{{ old('name', $classification?->name) }}">
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label for="color_badge" class="form-label fw-semibold">Color de la etiqueta</label>
        <input type="color" id="color_badge" name="color_badge" required
            class="form-control form-control-color w-100 @error('color_badge') is-invalid @enderror"
            value="{{ old('color_badge', $classification?->color_badge ?? '#6c757d') }}">
        @error('color_badge')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <label for="description" class="form-label fw-semibold">Descripción</label>
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
