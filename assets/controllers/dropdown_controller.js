import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['menu', 'button'];

    connect() {
        document.addEventListener('click', this.handleClickOutside.bind(this));
        document.addEventListener('keydown', this.handleKeydown.bind(this));
    }

    disconnect() {
        document.removeEventListener('click', this.handleClickOutside.bind(this));
        document.removeEventListener('keydown', this.handleKeydown.bind(this));
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