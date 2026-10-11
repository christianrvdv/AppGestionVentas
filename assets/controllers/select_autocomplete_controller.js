import { Controller } from '@hotwired/stimulus';

/*
 * Búsqueda sobre un <select> (clientes, Fase 3c.2).
 *
 * Filtra las opciones mientras se escribe: es un autocomplete accesible
 * (el <select> sigue siendo el control real, con su teclado nativo) y no
 * necesita endpoint nuevo. Sin JavaScript el select se comporta igual,
 * solo sin filtro.
 */

export default class extends Controller {
    static targets = ['query', 'list'];

    connect() {
        this.onInput = () => this.filter();

        this.queryTarget.addEventListener('input', this.onInput);
        this.filter();
    }

    disconnect() {
        this.queryTarget.removeEventListener('input', this.onInput);
    }

    filter() {
        const needle = this.queryTarget.value.trim().toLowerCase();

        for (const option of this.listTarget.options) {
            if (option.value === '') {
                continue;
            }

            option.hidden = needle !== '' && !option.textContent.toLowerCase().includes(needle);
        }
    }
}
