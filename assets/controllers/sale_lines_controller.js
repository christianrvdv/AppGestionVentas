import { Controller } from '@hotwired/stimulus';

/*
 * Líneas de una venta en el navegador (Fase 3c.2).
 *
 * Hace tres cosas, ninguna vinculante:
 *   - Agregar y quitar líneas clonando el prototipo de Symfony.
 *   - Acotar el selector de ítems a la inversión elegida (una venta
 *     tiene UNA sola inversión) y mostrar su precio sugerido.
 *   - Estimar subtotal, total y ganancia bruta en vivo.
 *
 * El servidor vuelve a calcular todo al registrar: SaleService valida
 * stock, pertenencia del ítem y ganancia reconocida. Si este controller
 * desaparece, el form sigue funcionando (una línea y POST normal).
 *
 * Los campos de cada línea se buscan con [data-line-*] y no con targets:
 * un target de Stimulus apunta a UN solo elemento y acá hay uno por fila.
 */

const ROW = '[data-sale-links-row]';
const ITEM = '[data-line-item]';
const QUANTITY = '[data-line-quantity]';
const PRICE = '[data-line-price]';
const SUGGESTED = '[data-line-suggested-value]';
const SUBTOTAL = '[data-line-subtotal]';
const GROSS = '[data-line-gross]';
const WARNING = '[data-line-warning]';
const UNKNOWN = '—';

const money = new Intl.NumberFormat('es-ES', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const round = (value) => Math.round((value + Number.EPSILON) * 100) / 100;
const toNumber = (value) => {
    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : 0;
};
const selectedOption = (select) => (select && select.selectedOptions ? select.selectedOptions[0] : null);
const costOf = (option) => toNumber(option ? option.dataset.unitCost : 0);
const stockOf = (option) => (option ? Number.parseInt(option.dataset.stock, 10) : 0);

export default class extends Controller {
    static targets = ['rows', 'prototype', 'investment', 'total', 'profit'];

    connect() {
        this.index = this.rowsTarget.querySelectorAll(ROW).length;

        this.onInput = (event) => {
            if (event.target.matches(`${QUANTITY}, ${PRICE}`)) {
                this.update();
            }
        };

        this.onChange = (event) => {
            if (this.hasInvestmentTarget && event.target === this.investmentTarget) {
                this.filterItems();
                this.update();
                return;
            }

            if (event.target.matches(ITEM)) {
                this.update();
            }
        };

        this.element.addEventListener('input', this.onInput);
        this.element.addEventListener('change', this.onChange);

        this.filterItems();
        this.update();
    }

    disconnect() {
        this.element.removeEventListener('input', this.onInput);
        this.element.removeEventListener('change', this.onChange);
    }

    add(event) {
        event.preventDefault();

        const html = this.prototypeTarget.innerHTML.replaceAll('__line__', String(this.index++));
        this.rowsTarget.insertAdjacentHTML('beforeend', html);

        this.update();
    }

    remove(event) {
        event.preventDefault();

        const row = event.currentTarget.closest(ROW);
        row.remove();

        this.update();
    }

    /**
     * Deja visibles solo los ítems de la inversión elegida y descarta una
     * selección previa que ya no pertenezca.
     */
    filterItems() {
        const investment = this.hasInvestmentTarget ? this.investmentTarget.value : '';

        for (const select of this.element.querySelectorAll(ITEM)) {
            for (const option of select.options) {
                if (option.value === '') {
                    continue;
                }

                option.hidden = investment === '' || option.dataset.investmentId === investment;
            }

            for (const group of select.querySelectorAll('optgroup')) {
                group.hidden = !Array.from(group.querySelectorAll('option')).some((option) => !option.hidden);
            }

            const option = selectedOption(select);
            if (option && option.value !== '' && investment !== '' && option.dataset.investmentId !== investment) {
                select.value = '';
            }
        }
    }

    update() {
        let total = 0;
        let profit = 0;
        let known = false;

        for (const row of this.rowsTarget.querySelectorAll(ROW)) {
            const item = row.querySelector(ITEM);
            const option = selectedOption(item);
            const quantity = toNumber(row.querySelector(QUANTITY)?.value);
            const price = toNumber(row.querySelector(PRICE)?.value);
            const cost = costOf(option);
            const stock = option && option.value !== '' ? stockOf(option) : Number.POSITIVE_INFINITY;
            const subtotal = round(quantity * price);

            this.write(row.querySelector(SUBTOTAL), money.format(subtotal));
            this.write(row.querySelector(GROSS), cost > 0 ? money.format(round(subtotal - cost * quantity)) : UNKNOWN);
            this.write(
                row.querySelector(SUGGESTED),
                option && option.value !== '' ? money.format(toNumber(option.dataset.suggestedPrice)) : UNKNOWN
            );

            const warning = row.querySelector(WARNING);
            if (warning) {
                warning.hidden = quantity <= stock;
            }

            total += subtotal;

            if (cost > 0) {
                profit += subtotal - cost * quantity;
                known = true;
            }
        }

        if (this.hasTotalTarget) {
            this.write(this.totalTarget, money.format(round(total)));
        }

        if (this.hasProfitTarget) {
            this.write(this.profitTarget, known ? money.format(round(profit)) : UNKNOWN);
        }
    }

    write(element, text) {
        if (element) {
            element.textContent = text;
        }
    }
}
