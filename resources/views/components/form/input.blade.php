@props(['name', 'label', 'type' => 'text', 'value' => null, 'required' => false, 'hint' => null])

<x-form.label :for="$name" :required="$required">{{ $label }}</x-form.label>
<input type="{{ $type }}" id="{{ $name }}" name="{{ $name }}" @required($required)
    @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
    {{ $attributes->class(['form-control', 'is-invalid' => $errors->has($name)]) }}>
<div class="invalid-feedback">{{ $errors->first($name) ?: $hint }}</div>
