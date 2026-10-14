import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['menu', 'button'];

    connect() {
        this.onClickOutside = this.handleClickOutside.bind(this);
        this.onKeydown = this.handleKeydown.bind(this);
        document.addEventListener('click', this.onClickOutside);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('click', this.onClickOutside);
        document.removeEventListener('keydown', this.onKeydown);
    }

    toggle(event) {
        event.preventDefault();
        event.stopPropagation();
        this.menuTarget.classList.toggle('hidden');
        this.buttonTarget.setAttribute('aria-expanded', !this.menuTarget.classList.contains('hidden'));
    }

    close() {
        this.menuTarget.classList.add('hidden');
        this.buttonTarget.setAttribute('aria-expanded', 'false');
    }

    handleClickOutside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    handleKeydown(event) {
        if (event.key === 'Escape') {
            this.close();
        }
    }
}