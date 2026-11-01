/**
 * Instant search + live filtering.
 *
 * liveSearch: suggestions appear under a search box while the customer types
 *             (debounced, cached, cancellable, keyboard friendly).
 * liveFilter: re-renders a results region in place when any filter changes,
 *             so users never have to press a Search button.
 */
const suggestCache = new Map();

const escapeHtml = (s) =>
    String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const escapeRegex = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

export function registerSearch(Alpine) {
    Alpine.data('liveSearch', () => ({
        q: '',
        open: false,
        loading: false,
        searched: false,
        products: [],
        categories: [],
        active: -1,
        timer: null,
        controller: null,

        init() {
            this.q = this.$el.querySelector('input[name="q"]')?.value ?? '';
        },

        // Spread onto the <input> with x-bind="field".
        field: {
            autocomplete: 'off',
            spellcheck: 'false',
            role: 'combobox',
            'aria-autocomplete': 'list',
            ':aria-expanded'() {
                return this.open ? 'true' : 'false';
            },
            '@input'(e) {
                this.q = e.target.value;
                this.queue();
            },
            '@focus'() {
                if (this.searched && this.q.trim().length >= 2) this.open = true;
            },
            '@keydown'(e) {
                this.onKey(e);
            },
        },

        get term() {
            return this.q.trim();
        },

        queue() {
            clearTimeout(this.timer);
            if (this.term.length < 2) {
                this.controller?.abort();
                this.reset();
                return;
            }
            this.timer = setTimeout(() => this.search(), 160);
        },

        reset() {
            this.products = [];
            this.categories = [];
            this.searched = false;
            this.loading = false;
            this.open = false;
            this.active = -1;
        },

        async search() {
            const term = this.term;
            const key = term.toLowerCase();
            this.open = true;

            if (suggestCache.has(key)) {
                this.apply(suggestCache.get(key));
                return;
            }

            this.controller?.abort();
            this.controller = new AbortController();
            this.loading = true;

            try {
                const url = new URL(window.SMT.searchUrl);
                url.searchParams.set('q', term);
                const res = await fetch(url, { headers: { Accept: 'application/json' }, signal: this.controller.signal });
                if (!res.ok) throw new Error(String(res.status));
                const data = await res.json();
                suggestCache.set(key, data);
                if (this.term.toLowerCase() === key) this.apply(data);
            } catch (e) {
                if (e.name !== 'AbortError') this.loading = false;
            }
        },

        apply(data) {
            this.products = data.products ?? [];
            this.categories = data.categories ?? [];
            this.active = -1;
            this.loading = false;
            this.searched = true;
        },

        onKey(e) {
            if (!this.open) return;
            const max = this.products.length - 1;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.active = this.active >= max ? 0 : this.active + 1;
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.active = this.active <= 0 ? max : this.active - 1;
            } else if (e.key === 'Enter' && this.active >= 0 && this.products[this.active]) {
                e.preventDefault();
                window.location.href = this.products[this.active].url;
            } else if (e.key === 'Escape') {
                this.open = false;
            }
        },

        // Bold the typed words inside a result name (input is escaped first).
        mark(text) {
            const words = this.term.split(/\s+/).filter((w) => w.length > 1).map(escapeRegex);
            const safe = escapeHtml(text);
            if (!words.length) return safe;
            return safe.replace(new RegExp(`(${words.join('|')})`, 'gi'), '<mark class="bg-transparent font-extrabold text-brand">$1</mark>');
        },
    }));

    Alpine.data('liveFilter', (target) => ({
        loading: false,
        controller: null,
        form: null,

        init() {
            this.form = this.$el;
        },

        async run() {
            const params = new URLSearchParams();
            for (const [k, v] of new FormData(this.form)) {
                const value = String(v).trim();
                if (value !== '' && value !== 'all') params.set(k, value);
            }
            const query = params.toString();
            const base = this.form.action.split('#')[0];
            const url = base + (query ? `?${query}` : '');

            this.controller?.abort();
            const controller = (this.controller = new AbortController());
            this.loading = true;

            try {
                const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal });
                if (!res.ok) {
                    window.location.href = url;
                    return;
                }
                const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
                const fresh = doc.querySelector(target);
                const current = document.querySelector(target);
                if (fresh && current) current.replaceWith(fresh);
                window.history.replaceState(null, '', url);
            } catch (e) {
                if (e.name !== 'AbortError') window.location.href = url;
            } finally {
                if (this.controller === controller) this.loading = false;
            }
        },
    }));
}
