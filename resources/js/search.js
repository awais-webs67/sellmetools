/**
 * Instant 0ms real-time search + live filtering across every search bar.
 *
 * Uses the preloaded `window.SMT.catalog` index for instant 0ms keystroke response
 * starting from the 1st character, with automatic server fallback when needed.
 */
const suggestCache = new Map();

const escapeHtml = (s) =>
    String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const escapeRegex = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

function lookupLocalCatalog(rawTerm) {
    const catalog = window.SMT?.catalog;
    if (!catalog || !Array.isArray(catalog.products)) {
        return null;
    }

    const term = rawTerm.trim().toLowerCase();
    if (!term) {
        return { products: [], categories: [] };
    }

    const words = term.split(/\s+/).filter(Boolean).slice(0, 6);

    const scoredProducts = [];
    for (const p of catalog.products) {
        const nameLower = String(p.name || '').toLowerCase();
        const hay = String(p.search || nameLower);

        let allMatch = true;
        for (const w of words) {
            if (!hay.includes(w)) {
                allMatch = false;
                break;
            }
        }
        if (!allMatch) continue;

        let score = 0;
        if (nameLower === term) score += 100;
        else if (nameLower.startsWith(term)) score += 60;
        else if (nameLower.includes(' ' + term)) score += 40;
        else if (nameLower.includes(term)) score += 25;
        if (p.in_stock) score += 10;

        scoredProducts.push({ p, score });
    }

    scoredProducts.sort((a, b) => b.score - a.score);

    const categories = (catalog.categories || [])
        .filter((c) => {
            const hay = String(c.search || c.name || '').toLowerCase();
            return words.every((w) => hay.includes(w));
        })
        .slice(0, 4);

    return {
        products: scoredProducts.slice(0, 7).map((x) => x.p),
        categories,
    };
}

function createSearchBehavior(targetSelector = null) {
    return {
        q: '',
        open: false,
        loading: false,
        searched: false,
        products: [],
        categories: [],
        active: -1,
        timer: null,
        filterTimer: null,
        controller: null,
        filterController: null,
        form: null,

        init() {
            this.form = this.$el.tagName === 'FORM' ? this.$el : this.$el.closest('form');
            const input = this.$el.querySelector('input[name="q"]');
            this.q = input?.value ?? '';
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
                if (targetSelector) {
                    this.filterDomNow();
                    clearTimeout(this.filterTimer);
                    this.filterTimer = setTimeout(() => this.run(), 380);
                }
            },
            '@focus'() {
                if (this.q.trim().length >= 1) {
                    if (!this.searched) this.queue();
                    this.open = true;
                }
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
            if (this.term.length < 1) {
                this.controller?.abort();
                this.reset();
                return;
            }

            // 0ms instant in-memory search when preloaded catalog is available
            const local = lookupLocalCatalog(this.term);
            if (local) {
                this.open = true;
                this.apply(local);
                return;
            }

            this.timer = setTimeout(() => this.search(), 80);
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

        filterDomNow() {
            if (!targetSelector) return;
            const container = document.querySelector(targetSelector);
            if (!container) return;
            const cards = container.querySelectorAll('[data-search-text]');
            if (!cards.length) return;

            const words = this.term.toLowerCase().split(/\s+/).filter(Boolean);
            cards.forEach((card) => {
                if (!words.length) {
                    card.style.display = '';
                    return;
                }
                const hay = card.getAttribute('data-search-text') || '';
                const match = words.every((w) => hay.includes(w));
                card.style.display = match ? '' : 'none';
            });
        },

        async run() {
            if (!this.form || !targetSelector) return;
            const params = new URLSearchParams();
            for (const [k, v] of new FormData(this.form)) {
                const value = String(v).trim();
                if (value !== '' && value !== 'all') params.set(k, value);
            }
            const query = params.toString();
            const base = this.form.action.split('#')[0];
            const url = base + (query ? `?${query}` : '');

            this.filterController?.abort();
            const controller = (this.filterController = new AbortController());
            this.loading = true;

            try {
                const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal });
                if (!res.ok) {
                    window.location.href = url;
                    return;
                }
                const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
                const fresh = doc.querySelector(targetSelector);
                const current = document.querySelector(targetSelector);
                if (fresh && current) current.replaceWith(fresh);
                window.history.replaceState(null, '', url);
            } catch (e) {
                if (e.name !== 'AbortError') window.location.href = url;
            } finally {
                if (this.filterController === controller) this.loading = false;
            }
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
            const words = this.term.split(/\s+/).filter((w) => w.length >= 1).map(escapeRegex);
            const safe = escapeHtml(text);
            if (!words.length) return safe;
            return safe.replace(new RegExp(`(${words.join('|')})`, 'gi'), '<mark class="bg-transparent font-extrabold text-brand">$1</mark>');
        },
    };
}

export function registerSearch(Alpine) {
    Alpine.data('liveSearch', () => createSearchBehavior(null));
    Alpine.data('liveFilter', (target) => createSearchBehavior(target));
}
