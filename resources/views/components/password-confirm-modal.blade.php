{{--
    Modal que pide la contraseña de quien está logueado antes de una acción sensible.
    La contraseña se verifica SIEMPRE en el servidor (App\Actions\ConfirmPassword): máximo de
    intentos en config/auth.php ("confirmation") y, al agotarlos, se cierra la sesión. Esta modal
    solo es la interfaz; saltársela no sirve de nada. Una sola por página: <x-password-confirm-modal />

    USO 1: acciones sueltas (botón de un listado). El botón NO lleva formulario:

        <button type="button" data-confirm-action="{{ route('users.deactivate', $user) }}"
            data-confirm-method="PATCH" data-confirm-title="Desactivar usuario"
            data-confirm-message="¿Desactivar a Ana?">...</button>

    USO 2: formularios de alta o edición. El formulario lleva data-password-confirm:

        <form data-password-confirm="always" ...>                       pide la contraseña siempre
        <form data-password-confirm="changed"
              data-password-confirm-fields="role,email" ...>            solo si cambia alguno de esos campos
        (más data-confirm-title y data-confirm-message con los textos de la modal)

    Si la contraseña es incorrecta, el servidor redirige con el error de "current_password" y la modal
    se vuelve a abrir sola con los intentos que quedan. El servidor es quien decide cuándo hace falta
    la contraseña; "changed" solo evita pedirla de más. Lógica en resources/js/app.js.
--}}
@props(['id' => 'passwordConfirmModal'])

@php
    $pending = session('confirm_action');
    $reopen = $errors->has('current_password');
@endphp

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}Label" aria-hidden="true"
    data-bs-backdrop="static" data-bs-keyboard="false" data-password-confirm-modal
    data-reopen="{{ $reopen ? 'true' : 'false' }}">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ $pending['url'] ?? '#' }}" class="modal-content" data-password-confirm-form>
            @csrf
            <input type="hidden" name="_method" value="{{ $pending['method'] ?? 'POST' }}">
            <input type="hidden" name="confirm_title" value="{{ old('confirm_title') }}">
            <input type="hidden" name="confirm_message" value="{{ old('confirm_message') }}">

            <div class="modal-header">
                <h5 class="modal-title" id="{{ $id }}Label">
                    <i class="bi bi-shield-lock me-1"></i>
                    <span data-confirm-title>{{ old('confirm_title') ?: 'Confirma tu contraseña' }}</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body">
                <p data-confirm-message>{{ old('confirm_message') }}</p>

                <x-form.password name="current_password" label="Tu contraseña" required
                    hint="Ingresa tu contraseña para continuar." />

                <p class="small text-muted mt-3 mb-0">
                    Por seguridad tienes {{ config('auth.confirmation.max_attempts') }} intentos: al fallar el
                    último se cerrará tu sesión.
                </p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-shield-check me-1"></i> Confirmar
                </button>
            </div>
        </form>
    </div>
</div>
