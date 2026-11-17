import $ from 'jquery';
window.$ = window.jQuery = $;

import * as bootstrap from 'bootstrap';
window.bootstrap = bootstrap;

import DataTable from 'datatables.net-bs5';
window.DataTable = DataTable;

// DataTables Buttons & Exports
import 'datatables.net-buttons-bs5';
import 'datatables.net-buttons/js/buttons.html5.mjs';
import 'datatables.net-buttons/js/buttons.print.mjs';

import JSZip from 'jszip';
import pdfMake from 'pdfmake/build/pdfmake';
import pdfFonts from 'pdfmake/build/vfs_fonts';

window.JSZip = JSZip;
if (pdfMake && pdfFonts) {
    pdfMake.vfs = pdfFonts.pdfMake ? pdfFonts.pdfMake.vfs : pdfFonts;
    window.pdfMake = pdfMake;
}

// Campos de contraseña (<x-form.password>): botón para mostrar u ocultar el valor
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-password-toggle]');
    if (!button) {
        return;
    }

    const input = document.getElementById(button.getAttribute('aria-controls'));
    const show = input.type === 'password';
    const label = show ? 'Ocultar contraseña' : 'Mostrar contraseña';

    input.type = show ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(show));
    button.setAttribute('aria-label', label);
    button.title = label;
    button.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
});

// Al volver con el botón "atrás" el navegador restaura la página: vaciar y ocultar las contraseñas
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) {
        return;
    }

    // Red de seguridad: algunos navegadores restauran la página desde memoria aunque tenga
    // "no-store". Si era una página con sesión, se vuelve a pedir; si la sesión ya se cerró,
    // el servidor responde con el login.
    if (document.body.dataset.authenticated === 'true') {
        window.location.reload();

        return;
    }

    document.querySelectorAll('input[data-password-input]').forEach((input) => {
        input.value = '';
        input.type = 'password';
    });
});

// Validación del cliente (Bootstrap): formularios con clase "needs-validation"
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form.needs-validation').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });
});
