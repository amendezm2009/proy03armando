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

// Editor de la foto de perfil (<x-avatar-editor>): se carga aparte, solo en las páginas que lo tienen
if (document.querySelector('[data-avatar-editor]')) {
    import('./avatar-editor');
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

// Modal de confirmación de contraseña (<x-password-confirm-modal>). Va después de la validación del
// cliente para que esa se ejecute primero. La contraseña la verifica siempre el servidor.
document.addEventListener('DOMContentLoaded', () => {
    const modalElement = document.querySelector('[data-password-confirm-modal]');

    if (!modalElement) {
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const modalForm = modalElement.querySelector('[data-password-confirm-form]');
    const passwordInput = modalForm.querySelector('input[name="current_password"]');
    const methodInput = modalForm.querySelector('input[name="_method"]');
    const titleInput = modalForm.querySelector('input[name="confirm_title"]');
    const messageInput = modalForm.querySelector('input[name="confirm_message"]');
    const targetForm = document.querySelector('form[data-password-confirm]');

    const describe = (title, message) => {
        titleInput.value = title ?? '';
        messageInput.value = message ?? '';
        modalElement.querySelector('[data-confirm-title]').textContent = title || 'Confirma tu contraseña';
        modalElement.querySelector('[data-confirm-message]').textContent = message ?? '';
    };

    // Acciones sueltas: botones con data-confirm-action (listados)
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-confirm-action]');

        if (!trigger) {
            return;
        }

        event.preventDefault();
        modalForm.dataset.mode = 'action';
        modalForm.action = trigger.dataset.confirmAction;
        methodInput.value = trigger.dataset.confirmMethod || 'POST';
        describe(trigger.dataset.confirmTitle, trigger.dataset.confirmMessage);
        modal.show();
    });

    // Formularios de alta o edición: data-password-confirm="always" | "changed"
    if (targetForm) {
        const needsConfirmation = () => {
            if (targetForm.dataset.passwordConfirm === 'always') {
                return true;
            }

            return (targetForm.dataset.passwordConfirmFields || '').split(',').some((name) => {
                const field = targetForm.querySelector(`[name="${name}"]:not(:disabled)`);

                if (!field) {
                    return false;
                }

                if (field.tagName === 'SELECT') {
                    const initial = [...field.options].find((option) => option.defaultSelected);

                    return field.value !== (initial ? initial.value : '');
                }

                return field.value !== field.defaultValue;
            });
        };

        targetForm.addEventListener('submit', (event) => {
            if (
                targetForm.dataset.passwordConfirmed === 'true'
                || event.defaultPrevented
                || !targetForm.checkValidity()
                || !needsConfirmation()
            ) {
                return;
            }

            event.preventDefault();
            modalForm.dataset.mode = 'form';
            describe(targetForm.dataset.confirmTitle, targetForm.dataset.confirmMessage);
            modal.show();
        });

        modalForm.addEventListener('submit', (event) => {
            if (modalForm.dataset.mode !== 'form') {
                return;
            }

            event.preventDefault();

            let hidden = targetForm.querySelector('input[type="hidden"][name="current_password"]');

            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'current_password';
                targetForm.appendChild(hidden);
            }

            hidden.value = passwordInput.value;
            targetForm.dataset.passwordConfirmed = 'true';
            modal.hide();
            targetForm.requestSubmit();
        });
    }

    modalElement.addEventListener('shown.bs.modal', () => passwordInput.focus());

    modalElement.addEventListener('hidden.bs.modal', () => {
        passwordInput.value = '';
        passwordInput.type = 'password';
    });

    // Contraseña incorrecta: el servidor volvió a esta página con el error; se reabre la modal
    if (modalElement.dataset.reopen === 'true') {
        if (targetForm) {
            modalForm.dataset.mode = 'form';
            describe(targetForm.dataset.confirmTitle, targetForm.dataset.confirmMessage);
        } else {
            modalForm.dataset.mode = 'action';
            describe(titleInput.value, messageInput.value);
        }

        modal.show();
    }
});
