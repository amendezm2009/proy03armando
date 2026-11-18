// Editor de la foto de perfil (<x-avatar-editor>): elegir una imagen o tomar una foto, moverla y
// acercarla dentro de un recorte cuadrado (cropperjs) y enviar el resultado al formulario.
// Solo se carga en las páginas que tienen el editor (import dinámico en app.js).
// El servidor valida y vuelve a codificar la imagen: nada de esto es una medida de seguridad.
import Cropper from 'cropperjs';

const OUTPUT_SIZE = 512;
const MAX_ORIGINAL_BYTES = 10 * 1024 * 1024;
const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

const TEMPLATE = '<cropper-canvas background style="height: 300px;">'
    + '<cropper-image scalable translatable></cropper-image>'
    + '<cropper-shade></cropper-shade>'
    + '<cropper-handle action="move" plain></cropper-handle>'
    + '<cropper-selection id="avatarSelection" aspect-ratio="1" initial-coverage="0.8">'
    + '<cropper-grid role="grid" bordered covered></cropper-grid>'
    + '<cropper-crosshair centered></cropper-crosshair>'
    + '</cropper-selection>'
    + '</cropper-canvas>';

const root = document.querySelector('[data-avatar-editor]');

if (root) {
    const modalElement = root.querySelector('[data-avatar-modal]');
    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const steps = [...modalElement.querySelectorAll('[data-step]')];
    const errorBox = modalElement.querySelector('[data-avatar-error]');
    const fileInput = modalElement.querySelector('[data-avatar-file]');
    const cameraButton = modalElement.querySelector('[data-avatar-camera-open]');
    const cameraNote = modalElement.querySelector('[data-avatar-camera-note]');
    const video = modalElement.querySelector('[data-avatar-video]');
    const cropContainer = modalElement.querySelector('[data-avatar-crop]');
    const saveButton = modalElement.querySelector('[data-avatar-save]');
    const outputForm = root.querySelector('[data-avatar-form]');
    const outputInput = root.querySelector('[data-avatar-output]');

    let cropper = null;
    let objectUrl = null;
    let stream = null;

    const showStep = (name) => {
        steps.forEach((step) => {
            step.hidden = step.dataset.step !== name;
        });

        if (name !== 'camera') {
            stopCamera();
        }
    };

    const showError = (message) => {
        errorBox.textContent = message ?? '';
        errorBox.hidden = !message;
    };

    function stopCamera() {
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        video.srcObject = null;
    }

    const destroyCropper = () => {
        cropper?.destroy();
        cropper = null;

        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }

        cropContainer.replaceChildren();
    };

    const reset = () => {
        destroyCropper();
        stopCamera();
        fileInput.value = '';
        showError(null);
        showStep('choose');
        saveButton.disabled = false;
    };

    // Muestra el recorte sobre la imagen (un archivo elegido o una captura de la cámara)
    const startCrop = (blob) => {
        destroyCropper();
        showError(null);

        objectUrl = URL.createObjectURL(blob);
        const image = new Image();
        image.alt = '';
        image.addEventListener('error', () => {
            // El archivo dice ser una imagen pero no se puede leer (corrupto o de otro tipo)
            destroyCropper();
            fileInput.value = '';
            showStep('choose');
            showError('No pudimos leer esa imagen. Elige otra.');
        });
        image.src = objectUrl;
        cropContainer.appendChild(image);

        cropper = new Cropper(image, { container: cropContainer, template: TEMPLATE });
        showStep('crop');
    };

    const zoom = (amount) => cropper?.getCropperImage()?.$zoom(amount);

    // --- Elegir una imagen ---------------------------------------------------------------
    modalElement.querySelector('[data-avatar-pick]').addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', () => {
        const file = fileInput.files[0];

        if (!file) {
            return;
        }

        if (!ALLOWED_TYPES.includes(file.type)) {
            showError('Elige una imagen JPG, PNG o WebP.');
            fileInput.value = '';

            return;
        }

        if (file.size > MAX_ORIGINAL_BYTES) {
            showError('La imagen pesa demasiado (máximo 10 MB). Elige una más pequeña.');
            fileInput.value = '';

            return;
        }

        startCrop(file);
    });

    // --- Tomar una foto ------------------------------------------------------------------
    if (navigator.mediaDevices?.getUserMedia) {
        cameraButton.hidden = false;
    } else {
        cameraNote.hidden = false;
    }

    cameraButton.addEventListener('click', async () => {
        showError(null);
        showStep('camera');

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 1280 } },
                audio: false,
            });
            video.srcObject = stream;
            await video.play();
        } catch {
            showStep('choose');
            showError('No pudimos usar la cámara. Revisa el permiso del navegador o elige una imagen.');
        }
    });

    modalElement.querySelector('[data-avatar-capture]').addEventListener('click', () => {
        if (!video.videoWidth) {
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

        canvas.toBlob((blob) => blob && startCrop(blob), 'image/jpeg', 0.92);
    });

    // --- Mover y acercar -----------------------------------------------------------------
    modalElement.querySelector('[data-avatar-zoom-in]').addEventListener('click', () => zoom(0.1));
    modalElement.querySelector('[data-avatar-zoom-out]').addEventListener('click', () => zoom(-0.1));

    modalElement.querySelectorAll('[data-avatar-back]').forEach((button) => {
        button.addEventListener('click', () => {
            destroyCropper();
            fileInput.value = '';
            showStep('choose');
        });
    });

    // --- Guardar -------------------------------------------------------------------------
    saveButton.addEventListener('click', async () => {
        const selection = cropper?.getCropperSelection();

        if (!selection) {
            return;
        }

        saveButton.disabled = true;

        const canvas = await selection.$toCanvas({
            width: OUTPUT_SIZE,
            height: OUTPUT_SIZE,
            beforeDraw: (context, target) => {
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, target.width, target.height);
            },
        });

        canvas.toBlob((blob) => {
            if (!blob) {
                saveButton.disabled = false;
                showError('No pudimos preparar la imagen. Inténtalo con otra.');

                return;
            }

            const transfer = new DataTransfer();
            transfer.items.add(new File([blob], 'avatar.jpg', { type: 'image/jpeg' }));
            outputInput.files = transfer.files;
            outputForm.requestSubmit();
        }, 'image/jpeg', 0.92);
    });

    modalElement.addEventListener('hidden.bs.modal', reset);
    window.addEventListener('pagehide', stopCamera);
}
