// Actualizaciones desde la web (/admin/actualizaciones): sube las partes de un paquete de una en una, lo verifica y
// repite los pasos de la instalación hasta que termina. Toda la lógica de seguridad vive en el servidor; esto solo
// llama a sus rutas. Se escribe con textContent (nunca innerHTML) para no interpretar nada que venga del servidor.

const root = document.querySelector('[data-updates]');

if (root) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const $ = (selector) => root.querySelector(selector);
    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const passwordInput = () => root.querySelector('input[name="current_password"]');

    const request = async (url, { method = 'POST', fields = {}, file = null } = {}) => {
        const body = new FormData();

        Object.entries(fields).forEach(([name, value]) => body.append(name, value));

        if (file) {
            body.append('file', file, file.name);
        }

        const response = await fetch(url, {
            method,
            body: method === 'DELETE' ? undefined : body,
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        });

        let data = {};

        try {
            data = await response.json();
        } catch {
            data = {};
        }

        if (!response.ok) {
            const first = data.errors ? Object.values(data.errors).flat()[0] : null;

            throw new Error(first ?? data.message ?? `Error ${response.status}`);
        }

        return data;
    };

    const show = (element, message) => {
        element.textContent = message ?? '';
        element.hidden = !message;
    };

    // ---- 1. Subir -------------------------------------------------------------------------------------------
    const uploadList = $('[data-upload-list]');
    const uploadError = $('[data-upload-error]');

    $('[data-upload-button]')?.addEventListener('click', async () => {
        show(uploadError, '');
        uploadList.replaceChildren();

        const files = [...$('[data-upload-input]').files];
        const versions = new Set(files.map((file) => /^app-v(\d+\.\d+\.\d+)\.zip\.\d{3}$/.exec(file.name)?.[1]).filter(Boolean));

        if (files.length === 0 || versions.size !== 1) {
            show(uploadError, 'Selecciona las partes de UNA sola versión (app-vX.Y.Z.zip.001…) junto con manifest.json y manifest.json.sig.');

            return;
        }

        const [version] = versions;
        const items = files.map((file) => {
            const item = document.createElement('li');

            item.textContent = `${file.name}: en espera`;
            uploadList.append(item);

            return item;
        });

        for (const [index, file] of files.entries()) {
            items[index].textContent = `${file.name}: subiendo…`;

            try {
                await request(root.dataset.uploadUrl, { fields: { version }, file });
                items[index].textContent = `${file.name}: listo`;
                items[index].className = 'text-success';
            } catch (error) {
                items[index].textContent = `${file.name}: ${error.message}`;
                items[index].className = 'text-danger';
                show(uploadError, 'La subida se detuvo. Corrige el problema y vuelve a subir los archivos.');

                return;
            }
        }

        window.location.reload();
    });

    // ---- 2. Verificar y descartar ---------------------------------------------------------------------------
    const report = $('[data-report]');
    let selectedVersion = null;

    const renderReport = (data) => {
        report.hidden = false;
        selectedVersion = data.version;
        $('[data-report-title]').textContent = `Paquete ${data.version}`;
        show($('[data-report-problems]'), data.problems.join(' '));
        show($('[data-report-warnings]'), data.warnings.join(' '));

        const summary = $('[data-report-summary]');

        summary.replaceChildren();
        summary.hidden = !data.summary;

        if (data.summary) {
            const rows = [
                ['Versión / build', `${data.summary.version} / ${data.summary.build}`],
                ['Reemplaza a', data.summary.previous_version ?? '—'],
                ['Firmado con la clave', data.summary.signing_key ?? '—'],
                ['PHP necesario', data.summary.requires_php ?? '—'],
                ['Archivos', String(data.summary.files)],
                ['Migraciones', String(data.summary.migrations.length)],
                ['Instalada ahora', `${data.installed.version} (build ${data.installed.build})`],
            ];

            rows.forEach(([name, value]) => {
                const term = document.createElement('dt');
                const detail = document.createElement('dd');

                term.className = 'col-sm-4';
                detail.className = 'col-sm-8';
                term.textContent = name;
                detail.textContent = value;
                summary.append(term, detail);
            });
        }

        $('[data-install-box]').hidden = !data.ok;
        $('[data-install-version]').textContent = data.version;
    };

    root.addEventListener('click', async (event) => {
        const inspect = event.target.closest('[data-inspect]');
        const discard = event.target.closest('[data-discard]');

        if (inspect) {
            inspect.disabled = true;
            inspect.textContent = 'Verificando…';

            try {
                renderReport(await request(root.dataset.inspectUrl, { fields: { version: inspect.dataset.inspect } }));
            } catch (error) {
                renderReport({ version: inspect.dataset.inspect, ok: false, problems: [error.message], warnings: [], summary: null, installed: {} });
            }

            inspect.disabled = false;
            inspect.textContent = 'Verificar';
        }

        if (discard && window.confirm(`¿Descartar los archivos subidos de la versión ${discard.dataset.discard}?`)) {
            try {
                await request(root.dataset.discardUrl.replace('0.0.0', discard.dataset.discard), { method: 'DELETE' });
                window.location.reload();
            } catch (error) {
                show(uploadError, error.message);
            }
        }
    });

    // ---- 3. Instalar ----------------------------------------------------------------------------------------
    const panel = $('[data-progress]');
    const bar = $('[data-progress-bar]');
    const buttons = { resume: $('[data-resume]'), revert: $('[data-revert]'), reload: $('[data-reload]') };

    const render = (status) => {
        panel.hidden = false;
        $('[data-progress-label]').textContent = `Versión ${status.version}: ${status.label}`;
        bar.style.width = `${status.percent}%`;
        bar.parentElement.setAttribute('aria-valuenow', String(status.percent));
        bar.classList.toggle('progress-bar-animated', !status.finished);
        bar.classList.toggle('bg-success', status.ok);
        bar.classList.toggle('bg-danger', status.finished && !status.ok);
        show($('[data-progress-error]'), status.error);
        $('[data-progress-log]').textContent = status.log.map((line) => `${line.at}  ${line.text}`).join('\n');
        buttons.reload.hidden = !status.finished;
        buttons.resume.hidden = status.finished;
        buttons.revert.hidden = status.finished;
    };

    let running = false;

    const run = async () => {
        if (running) {
            return;
        }

        running = true;
        buttons.resume.hidden = true;

        try {
            for (;;) {
                const status = await request(root.dataset.stepUrl);

                render(status);

                if (status.finished) {
                    break;
                }

                if (status.busy) {
                    await sleep(1500);
                }
            }
        } catch (error) {
            show($('[data-progress-error]'), `${error.message} Puedes reanudar o revertir.`);
            buttons.resume.hidden = false;
            buttons.revert.hidden = false;
        } finally {
            running = false;
        }
    };

    $('[data-install-button]')?.addEventListener('click', async () => {
        const password = passwordInput()?.value ?? '';

        if (!$('[data-install-ack]').checked || password === '') {
            show($('[data-report-problems]'), 'Escribe tu contraseña y marca la casilla para instalar.');

            return;
        }

        try {
            const status = await request(root.dataset.startUrl, { fields: { version: selectedVersion, current_password: password } });

            passwordInput().value = '';
            $('[data-install-box]').hidden = true;
            render(status);
            await run();
        } catch (error) {
            show($('[data-report-problems]'), error.message);
        }
    });

    buttons.resume.addEventListener('click', run);
    buttons.reload.addEventListener('click', () => window.location.reload());

    buttons.revert.addEventListener('click', async () => {
        const password = window.prompt('Para revertir la instalación escribe tu contraseña:');

        if (!password) {
            return;
        }

        try {
            render(await request(root.dataset.revertUrl, { fields: { current_password: password } }));
        } catch (error) {
            show($('[data-progress-error]'), error.message);
        }
    });

    // Si la página se abre con una instalación a medias (se cerró el navegador, por ejemplo), se muestra su avance.
    const initial = JSON.parse(root.dataset.status || 'null');

    if (initial) {
        render(initial);

        if (!initial.finished) {
            buttons.resume.hidden = false;
            buttons.revert.hidden = false;
        }
    }
}
