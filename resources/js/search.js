/**
 * Instant 0ms real-time search + live filtering across every search bar.
 *
 * Uses the preloaded `window.SMT.catalog` index for instant 0ms keystroke response
 * starting from the 1st character, with automatic server fallback when needed.
 */
const suggestCache = new Map();
const pageHtmlCache = new Map();
let catalogPromise = null;

function ensureCatalogLoaded() {
    if (window.SMT?.catalog && Array.isArray(window.SMT.catalog.products)) {
        return Promise.resolve(window.SMT.catalog);
    }

    try {
        const cached = sessionStorage.getItem('smt_catalog_cache_v1');
        if (cached) {
            const parsed = JSON.parse(cached);
            if (parsed && parsed.ts && Date.now() - parsed.ts < 300000 && parsed.data) {
                window.SMT = window.SMT || {};
                window.SMT.catalog = parsed.data;
                return Promise.resolve(parsed.data);
            }
        }
    } catch (_) {}

    if (catalogPromise) {
        return catalogPromise;
    }

    if (!window.SMT?.searchUrl) {
        return Promise.resolve(null);
    }

    const url = new URL(window.SMT.searchUrl, window.location.origin);
    url.searchParams.set('catalog', '1');

    catalogPromise = fetch(url, { headers: { Accept: 'application/json' } })
        .then((res) => (res.ok ? res.json() : null))
        .then((data) => {
            if (data && Array.isArray(data.products)) {
                window.SMT.catalog = data;
                try {
                    sessionStorage.setItem('smt_catalog_cache_v1', JSON.stringify({ ts: Date.now(), data }));
                } catch (_) {}
                return data;
            }
            return null;
        })
        .catch(() => null)
        .finally(() => {
            catalogPromise = null;
        });

    return catalogPromise;
}

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
            this.form =
                this.$el.tagName === 'FORM'
                    ? this.$el
                    : this.$el.querySelector('form') || this.$el.closest('form');
            const input = (this.form || this.$el).querySelector('input[name="q"]');
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
                ensureCatalogLoaded();
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
                // If catalog has >80 products and local matches are few, also query server in background
                if (window.SMT?.catalog?.has_more && local.products.length < 5) {
                    this.timer = setTimeout(() => this.search(), 90);
                }
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

        highlightPill(clickedEl) {
            const nav = clickedEl?.closest('nav');
            if (!nav) return;
            nav.querySelectorAll('a').forEach((a) => {
                const isTarget = a === clickedEl;
                a.classList.toggle('border-brand', isTarget);
                a.classList.toggle('bg-brand', isTarget);
                a.classList.toggle('text-white', isTarget);
                a.classList.toggle('border-line-strong', !isTarget);
                a.classList.toggle('bg-white', !isTarget);
                a.classList.toggle('text-ink', !isTarget);
                a.classList.toggle('hover:border-brand', !isTarget);
                a.classList.toggle('hover:text-brand', !isTarget);
                const badge = a.querySelector('span.rounded-full');
                if (badge) {
                    badge.classList.toggle('bg-white/20', isTarget);
                    badge.classList.toggle('text-white', isTarget);
                    badge.classList.toggle('bg-grey-soft', !isTarget);
                    badge.classList.toggle('text-muted', !isTarget);
                }
            });
        },

        prefetch(rawUrl) {
            if (!rawUrl) return;
            const url = String(rawUrl).split('#')[0];
            const cached = pageHtmlCache.get(url);
            if (cached && Date.now() - cached.ts < 60000) return;

            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then((res) => (res.ok ? res.text() : null))
                .then((html) => {
                    if (html) pageHtmlCache.set(url, { html, ts: Date.now() });
                })
                .catch(() => {});
        },

        setCategory(slug, event = null) {
            if (event?.currentTarget) {
                this.highlightPill(event.currentTarget);
            }
            if (this.form) {
                let catInput = this.form.querySelector('input[name="category"]');
                if (slug) {
                    if (!catInput) {
                        catInput = document.createElement('input');
                        catInput.type = 'hidden';
                        catInput.name = 'category';
                        this.form.prepend(catInput);
                    }
                    catInput.value = slug;
                } else if (catInput) {
                    catInput.remove();
                }
            }
            this.open = false;
            this.run();
        },

        switchUrl(rawUrl, event = null) {
            if (event?.currentTarget) {
                this.highlightPill(event.currentTarget);
            }
            const cleanBase = String(rawUrl).split('#')[0];
            if (this.form) {
                this.form.action = cleanBase;
            }
            this.open = false;
            this.runUrl(cleanBase);
        },

        applyHtmlSwap(html, url) {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const fresh = doc.querySelector(targetSelector);
            const current = document.querySelector(targetSelector);
            if (fresh && current) {
                current.replaceWith(fresh);
            }
            const freshMeta = doc.querySelector('[data-filter-summary]');
            const currentMeta = document.querySelector('[data-filter-summary]');
            if (freshMeta && currentMeta) {
                currentMeta.replaceWith(freshMeta);
            } else if (!freshMeta && currentMeta) {
                currentMeta.remove();
            }
            window.history.replaceState(null, '', url);
        },

        async run() {
            if (!this.form || !targetSelector) return;
            const params = new URLSearchParams();
            for (const [k, v] of new FormData(this.form)) {
                const value = String(v).trim();
                if (value !== '' && value !== 'all' && !(k === 'sort' && value === 'featured')) {
                    params.set(k, value);
                }
            }
            const query = params.toString();
            const base = this.form.action.split('#')[0];
            const url = base + (query ? `?${query}` : '');

            await this.runUrl(url);
        },

        async runUrl(url) {
            if (!targetSelector) return;

            this.filterController?.abort();

            const cached = pageHtmlCache.get(url);
            if (cached && Date.now() - cached.ts < 60000) {
                this.loading = false;
                this.applyHtmlSwap(cached.html, url);
                return;
            }

            const controller = (this.filterController = new AbortController());
            this.loading = true;
            const currentEl = document.querySelector(targetSelector);
            if (currentEl) {
                currentEl.classList.add('opacity-55', 'pointer-events-none', 'transition-opacity', 'duration-150');
            }

            try {
                const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal });
                if (!res.ok) {
                    window.location.href = url;
                    return;
                }
                const html = await res.text();
                pageHtmlCache.set(url, { html, ts: Date.now() });
                this.applyHtmlSwap(html, url);
            } catch (e) {
                if (e.name !== 'AbortError') window.location.href = url;
            } finally {
                if (this.filterController === controller) {
                    this.loading = false;
                    const el = document.querySelector(targetSelector);
                    if (el) {
                        el.classList.remove('opacity-55', 'pointer-events-none');
                    }
                }
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
