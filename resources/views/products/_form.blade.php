@php($product = $product ?? null)

@csrf

<p class="text-muted small mb-2"><span class="text-danger">*</span> Campo obligatorio</p>

{{-- Dos columnas para que todo quepa sin hacer scroll: a la izquierda nombre, descripción y clasificaciones
     (8 de 12 columnas); a la derecha el resto de campos agrupados. En pantallas pequeñas se apilan. --}}
<div class="row g-3">
    <div class="col-md-8">
        <div class="row g-3 mb-3">
            <div class="col-sm-8">
                <x-form.label for="name" required>Nombre</x-form.label>
                <input type="text" id="name" name="name" minlength="3" maxlength="150" required
                    class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $product?->name) }}">
                <div class="invalid-feedback">@error('name'){{ $message }}@else Ingresa el nombre (de 3 a 150 caracteres).@enderror</div>
            </div>

            {{-- En la misma línea que el nombre y el SKU; alineado con la base de sus campos. --}}
            <div class="col-sm-4 d-flex align-items-end">
                <input type="hidden" name="is_active" value="0">
                <div class="form-check form-switch mb-0 py-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active"
                        value="1" @checked(old('is_active', $product?->is_active ?? true))>
                    <label class="form-check-label" for="is_active">Producto activo</label>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <x-form.label for="description">Descripción</x-form.label>
            <textarea id="description" name="description" rows="5" maxlength="5000"
                class="form-control @error('description') is-invalid @enderror">{{ old('description', $product?->description) }}</textarea>
            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div>
            <x-form.label for="classification_ids">Clasificaciones</x-form.label>
            {{-- El valor vacío permite quitarlas todas: sin él, no elegir ninguna equivaldría a "no cambiar". --}}
            <input type="hidden" name="classification_ids[]" value="">
            <x-classification-picker id="classification_ids" name="classification_ids[]"
                :selected="$selectedClassifications" only-active />
            @error('classification_ids')<div class="text-danger small">{{ $message }}</div>@enderror
            @error('classification_ids.*')<div class="text-danger small">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="col-md-4">
        <div class="row g-3">
            <div class="col-12">
                <x-form.label for="sku">SKU</x-form.label>
                <input type="text" id="sku" name="sku" maxlength="40" pattern="[A-Za-z0-9._\-]+"
                    class="form-control @error('sku') is-invalid @enderror" value="{{ old('sku', $product?->sku) }}"
                    placeholder="{{ $product ? '' : 'Automático' }}" aria-describedby="skuHelp">
                <div class="invalid-feedback">@error('sku'){{ $message }}@else Solo letras, números, punto, guion y guion bajo.@enderror</div>
                <div id="skuHelp" class="form-text">{{ $product ? 'Vacío = conservar el actual.' : 'Vacío = se genera solo.' }}</div>
            </div>

            <div class="col-12">
                <x-form.label for="barcode">Código de barras (EAN-13)</x-form.label>
                <input type="text" id="barcode" name="barcode" maxlength="13" inputmode="numeric" pattern="\d{13}"
                    class="form-control @error('barcode') is-invalid @enderror"
                    value="{{ old('barcode', $product?->barcode) }}" placeholder="{{ $product ? '' : 'Automático' }}"
                    aria-describedby="barcodeHelp">
                <div class="invalid-feedback">@error('barcode'){{ $message }}@else Debe tener 13 dígitos.@enderror</div>
                <div id="barcodeHelp" class="form-text">{{ $product ? 'Vacío = conservar el actual.' : 'Vacío = EAN-13 interno.' }}</div>
            </div>

            <div class="col-6">
                <x-form.label for="stock" required>Existencias</x-form.label>
                <input type="number" id="stock" name="stock" min="0" max="9999999" step="1" required
                    class="form-control @error('stock') is-invalid @enderror"
                    value="{{ old('stock', $product?->stock ?? 0) }}">
                <div class="invalid-feedback">@error('stock'){{ $message }}@else Entero, 0 o más.@enderror</div>
            </div>

            <div class="col-6">
                <x-form.label for="min_stock" required>Stock mínimo</x-form.label>
                <input type="number" id="min_stock" name="min_stock" min="0" max="9999999" step="1" required
                    class="form-control @error('min_stock') is-invalid @enderror"
                    value="{{ old('min_stock', $product?->min_stock ?? 0) }}">
                <div class="invalid-feedback">@error('min_stock'){{ $message }}@else Entero, 0 o más.@enderror</div>
            </div>

            <div class="col-6">
                <x-form.label for="purchase_price" required>P. compra</x-form.label>
                <input type="number" id="purchase_price" name="purchase_price" min="0" max="99999999.99" step="0.01"
                    required class="form-control @error('purchase_price') is-invalid @enderror"
                    value="{{ old('purchase_price', $product?->purchase_price ?? '0.00') }}">
                <div class="invalid-feedback">@error('purchase_price'){{ $message }}@else Hasta 2 decimales.@enderror</div>
            </div>

            <div class="col-6">
                <x-form.label for="sale_price" required>P. venta</x-form.label>
                <input type="number" id="sale_price" name="sale_price" min="0" max="99999999.99" step="0.01" required
                    class="form-control @error('sale_price') is-invalid @enderror"
                    value="{{ old('sale_price', $product?->sale_price ?? '0.00') }}">
                <div class="invalid-feedback" id="salePriceFeedback">@error('sale_price'){{ $message }}@else Importe con hasta 2 decimales.@enderror</div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        // Avisa antes de enviar si el precio de venta no es mayor que el de compra (el servidor lo vuelve a validar).
        document.addEventListener('DOMContentLoaded', function() {
            const purchase = document.getElementById('purchase_price');
            const sale = document.getElementById('sale_price');
            const feedback = document.getElementById('salePriceFeedback');
            const message = @json(__('validation.sale_price_above_purchase'));
            const initial = feedback.textContent;

            function check() {
                const invalid = sale.value !== '' && purchase.value !== '' &&
                    parseFloat(sale.value) <= parseFloat(purchase.value);
                sale.setCustomValidity(invalid ? message : '');
                feedback.textContent = invalid ? message : initial;
            }

            purchase.addEventListener('input', check);
            sale.addEventListener('input', check);
            check();
        });
    </script>
@endpush

<div class="d-flex flex-wrap align-items-center gap-2 mt-3">
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1"></i> Guardar
    </button>
    <a href="{{ $product ? route('products.show', $product) : route('products.index') }}"
        class="btn btn-outline-secondary">Cancelar</a>
</div>
