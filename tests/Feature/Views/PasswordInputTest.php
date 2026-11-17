<?php

// En una petición real Laravel comparte $errors con las vistas; con blade() hay que darlo.
beforeEach(fn () => $this->withViewErrors([]));

it('muestra un campo de contraseña con el botón de mostrar u ocultar', function () {
    $this->blade('<x-form.password name="password" label="Contraseña" />')
        ->assertSee('type="password"', false)
        ->assertSee('data-password-toggle', false)
        ->assertSee('aria-label="Mostrar contraseña"', false);
});

it('pide al navegador y a los gestores de contraseñas que no rellenen el campo', function () {
    $this->blade('<x-form.password name="password" label="Contraseña" />')
        ->assertSee('autocomplete="new-password"', false)
        ->assertSee('data-lpignore="true"', false)
        ->assertSee('data-1p-ignore', false)
        ->assertSee('data-bwignore', false);
});

it('ignora el autocomplete que se le pase', function () {
    $this->blade('<x-form.password name="password" label="Contraseña" autocomplete="current-password" />')
        ->assertDontSee('current-password', false);
});

it('nunca escribe el valor en el HTML, ni siquiera tras un error de validación', function () {
    session()->flashInput(['password' => 'secreto-seguro-1']);

    $this->blade('<x-form.password name="password" label="Contraseña" value="secreto-seguro-1" />')
        ->assertDontSee('secreto-seguro-1', false);
});

it('muestra el asterisco cuando el campo es obligatorio', function () {
    $this->blade('<x-form.password name="password" label="Contraseña" required />')
        ->assertSee('text-danger', false)
        ->assertSee('required', false);
});

it('muestra el mensaje de error del campo', function () {
    $this->withViewErrors(['password' => 'Contraseña inválida.'])
        ->blade('<x-form.password name="password" label="Contraseña" />')
        ->assertSee('is-invalid', false)
        ->assertSee('Contraseña inválida.');
});
