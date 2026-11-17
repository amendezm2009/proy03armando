{{--
    Campo de contraseña reutilizable, con botón para mostrarla u ocultarla.

    Uso:  <x-form.password name="password" label="Contraseña" required minlength="8" hint="Mínimo 8 caracteres." />

    Seguridad:
      - Nunca se escribe el valor en el HTML (ni siquiera con old()), así que no queda en el
        código fuente de la página tras un error de validación.
      - autocomplete="new-password" y los atributos data-* piden al navegador y a los gestores
        de contraseñas (LastPass, 1Password, Bitwarden) que no rellenen el campo. Es una
        petición, no una garantía: depende de cada navegador o extensión.
      - Al volver con el botón "atrás" el campo se vacía (resources/js/app.js).
      - Los atributos autocomplete y value que se pasen al componente se ignoran a propósito.
--}}
@props(['name', 'label', 'required' => false, 'hint' => null])

<x-form.label :for="$name" :required="$required">{{ $label }}</x-form.label>
<div class="input-group has-validation">
    <input type="password" id="{{ $name }}" name="{{ $name }}" @required($required) data-password-input
        autocomplete="new-password" autocapitalize="off" autocorrect="off" spellcheck="false"
        data-lpignore="true" data-1p-ignore data-bwignore data-form-type="other"
        {{ $attributes->except(['autocomplete', 'value'])->class(['form-control', 'is-invalid' => $errors->has($name)]) }}>
    <button type="button" class="btn btn-outline-secondary" data-password-toggle aria-controls="{{ $name }}"
        aria-pressed="false" aria-label="Mostrar contraseña" title="Mostrar contraseña">
        <i class="bi bi-eye"></i>
    </button>
    <div class="invalid-feedback">{{ $errors->first($name) ?: $hint }}</div>
</div>
