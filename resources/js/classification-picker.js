// Selector de clasificaciones con búsqueda en el servidor. Marcado y uso: components/classification-picker.blade.php
//
// - Al abrir la página pide la lista inicial; al enfocarlo vacío muestra las "Recientes" del usuario (cada una
//   con una ✕ para quitarla del historial) y, debajo, las "Sugeridas" (las primeras por nombre), para dar una
//   idea de qué clasificaciones hay.
// - Con menos de `data-min-chars` letras la lista no se filtra; con esas letras o más se busca en el servidor
//   y se muestran los "Resultados" (más lo ya cargado que coincida).
// - Al elegir una clasificación se anota en el historial en ese momento.
import TomSelect from 'tom-select';

const HEX_COLOR = /^#[0-9a-f]{6}$/i;
const colorOf = (data) => (HEX_COLOR.test(data.color ?? '') ? data.color : '#6c757d');
const badge = (data, escape) =>
    `<span class="badge badge-tag text-white" style="background-color:${colorOf(data)}">${escape(data.text)}</span>`;
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

function createPicker(select) {
    const minChars = Number(select.dataset.minChars) || 3;
    const recentUrl = (id) => select.dataset.recentUrl.replace('__ID__', encodeURIComponent(id));

    const send = (method, url) =>
        fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
        }).then((response) => {
            if (!response.ok) {
                throw response;
            }
        });

    const picker = new TomSelect(select, {
        plugins: ['remove_button'],
        maxItems: 50,
        maxOptions: 40,
        closeAfterSelect: true,
        // La lista inicial se pide al abrir la página, no al hacer clic, para que ya esté lista al enfocar.
        preload: true,
        // Espera mínima entre teclas antes de buscar (Tom Select también la aplica a la carga inicial).
        loadThrottle: 120,
        shouldLoad: (query) => query.length >= minChars,
        // Con menos letras de las necesarias no se filtra: se ve la lista inicial completa.
        score(search) {
            const base = this.getScoreFunction(search);

            return search.length < minChars ? () => 1 : base;
        },
        optgroups: [
            { value: 'recent', label: 'Recientes' },
            { value: 'other', label: '' },
        ],
        lockOptgroupOrder: true,
        load(query, callback) {
            const params = new URLSearchParams({ q: query, only_active: select.dataset.onlyActive });

            fetch(`${select.dataset.url}?${params}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then((response) => (response.ok ? response.json() : Promise.reject(response)))
                .then((json) => {
                    const items = json.data.map((item) => ({
                        value: String(item.id),
                        text: item.name,
                        color: item.color_badge,
                        recent: item.group === 'recent',
                        optgroup: item.group === 'recent' ? 'recent' : 'other',
                    }));

                    // Tom Select no actualiza una opción que ya tiene (p. ej. una ya elegida al cargar la página).
                    items
                        .filter((item) => item.recent && this.options[item.value])
                        .forEach((item) => this.updateOption(item.value, { ...this.options[item.value], ...item }));

                    callback(items);
                })
                .catch(() => callback());
        },
        render: {
            item: (data, escape) => `<div>${badge(data, escape)}</div>`,
            option: (data, escape) =>
                data.recent
                    ? `<div class="d-flex align-items-center justify-content-between gap-2">${badge(data, escape)}` +
                      `<button type="button" class="btn-close" style="font-size:.6rem" data-forget-id="${escape(data.value)}" ` +
                      `title="Quitar del historial" aria-label="Quitar ${escape(data.text)} del historial"></button></div>`
                    : `<div>${badge(data, escape)}</div>`,
            optgroup_header(data, escape) {
                const label = data.value === 'other'
                    ? (this.inputValue().length >= minChars ? 'Resultados' : 'Sugeridas')
                    : data.label;

                return `<div class="optgroup-header text-uppercase small fw-semibold text-muted px-2 pt-2">${escape(label)}</div>`;
            },
            loading: () => '<div class="px-2 py-1 text-muted small">Buscando…</div>',
            not_loading: () => `<div class="px-2 py-1 text-muted small">Escribe al menos ${minChars} letras para filtrar.</div>`,
            no_results: () => '<div class="px-2 py-1 text-muted small">Sin resultados.</div>',
        },
    });

    // Elegir una clasificación la anota en el historial al momento; si se quita el chip, queda como reciente.
    picker.on('item_add', (value) => {
        send('POST', recentUrl(value))
            .then(() => {
                const data = picker.options[value];

                if (data && !data.recent) {
                    picker.updateOption(value, { ...data, recent: true, optgroup: 'recent' });
                }
            })
            .catch(() => {});
    });

    // La ✕ de una reciente la quita del historial sin elegirla. Se intercepta en la fase de captura para que
    // Tom Select no lo trate como la selección de la opción.
    ['mousedown', 'click'].forEach((type) => {
        picker.dropdown.addEventListener(
            type,
            (event) => {
                const button = event.target.closest('[data-forget-id]');
                if (!button) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();

                if (type !== 'click') {
                    return;
                }

                const id = button.dataset.forgetId;

                send('DELETE', recentUrl(id))
                    .then(() => {
                        // Si está elegida, o se está viendo una búsqueda, la opción se queda pero ya no es "reciente".
                        if (picker.items.includes(id) || picker.inputValue() !== '') {
                            picker.updateOption(id, { ...picker.options[id], recent: false, optgroup: 'other' });
                        } else {
                            picker.removeOption(id);
                        }
                        picker.refreshOptions(false);
                    })
                    .catch(() => {});
            },
            true,
        );
    });

    return picker;
}

document.querySelectorAll('select[data-classification-picker]').forEach((select) => {
    if (!select.tomselect) {
        createPicker(select);
    }
});
