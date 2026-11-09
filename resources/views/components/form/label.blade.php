@props(['for', 'required' => false])

<label for="{{ $for }}" {{ $attributes->merge(['class' => 'form-label fw-semibold']) }}>
    {{ $slot }}@if($required)<span class="text-danger" aria-hidden="true"> *</span>@endif
</label>
