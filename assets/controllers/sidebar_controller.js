import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'gd.sidebar.collapsed';
const MOBILE_QUERY = '(width < 48rem)';

export default class extends Controller {
    static targets = ['panel', 'toggle', 'overlay'];

    connect() {
        if (!this.hasPanelTarget) {
            return;
        }

        if (this.readCollapsed()) {
            this.panelTarget.dataset.collapsed = 'true';
        }

        this.syncToggles();
        this.trapping = false;
        this.onKeydown = this.handleKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
    }

    toggleCollapse() {
        const collapsed = this.panelTarget.dataset.collapsed === 'true';

        if (collapsed) {
            delete this.panelTarget.dataset.collapsed;
        } else {
            this.panelTarget.dataset.collapsed = 'true';
        }

        this.writeCollapsed(!collapsed);
        this.syncToggles();
    }

    open() {
        if (this.panelTarget.dataset.open === 'true') {
            return;
        }

        this.previouslyFocused = document.activeElement;
        this.panelTarget.dataset.open = 'true';

        if (this.hasOverlayTarget) {
            this.overlayTarget.classList.remove('hidden');
        }

        this.syncToggles();

        const first = this.focusables()[0];
        if (first) {
            first.focus();
        }

        this.trapping = true;
    }

    close() {
        if (this.panelTarget.dataset.open !== 'true') {
            return;
        }

        delete this.panelTarget.dataset.open;

        if (this.hasOverlayTarget) {
            this.overlayTarget.classList.add('hidden');
        }

        this.trapping = false;
        this.syncToggles();

        if (this.previouslyFocused && document.contains(this.previouslyFocused)) {
            this.previouslyFocused.focus();
        }
    }

    handleKeydown(event) {
        if (this.panelTarget.dataset.open !== 'true') {
            return;
        }

        if (event.key === 'Escape') {
            this.close();

            return;
        }

        if (event.key !== 'Tab' || !this.trapping || !window.matchMedia(MOBILE_QUERY).matches) {
            return;
        }

        const items = this.focusables();
        if (items.length === 0) {
            return;
        }

        const first = items[0];
        const last = items[items.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    focusables() {
        return Array.from(
            this.panelTarget.querySelectorAll('a[href], button:not([disabled])')
        ).filter((element) => element.offsetParent !== null);
    }

    syncToggles() {
        const open = this.panelTarget.dataset.open === 'true';
        const collapsed = this.panelTarget.dataset.collapsed === 'true';

        this.toggleTargets.forEach((element) => {
            if (element.dataset.sidebarToggle === 'collapse') {
                element.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            } else {
                element.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
        });
    }

    readCollapsed() {
        try {
            return window.localStorage.getItem(STORAGE_KEY) === '1';
        } catch (error) {
            return false;
        }
    }

    writeCollapsed(collapsed) {
        try {
            if (collapsed) {
                window.localStorage.setItem(STORAGE_KEY, '1');
            } else {
                window.localStorage.removeItem(STORAGE_KEY);
            }
        } catch (error) {
            // Storage no disponible (modo privado): el estado solo vive en la sesión.
        }
    }
}
