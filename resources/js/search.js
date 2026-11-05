/**
 * Instant real-time search + live filtering across every search bar.
 *
 * Always syncs live stock & price with the server (cache: 'no-store') and
 * Alpine.store('stock') so search results never show stale Sold Out / In Stock status.
 */
let catalogPromise = null;

try {
    sessionStorage.removeItem('smt_catalog_cache_v1');
} catch (_) {}

function syncProductsWithStockStore(products) {
    if (!Array.isArray(products)) return;
    const stockStore = window.Alpine?.store?.('stock');
    if (!stockStore) return;

    for (const p of products) {
        if (!p || !p.id) continue;
        const id = String(p.id);
        stockStore.items[id] = {
            qty: typeof p.qty === 'number' ? p.qty : p.in_stock ? 1 : 0,
            unlimited: Boolean(p.unlimited),
            in_stock: Boolean(p.in_stock),
            price: Number(p.price_raw ?? 0),
            retail_price: Number(p.price_raw ?? 0),
            price_formatted: p.price,
        };
    }
}

function ensureCatalogLoaded(force = false) {
    const now = Date.now();
    if (
        !force &&
        window.SMT?.catalog &&
        Array.isArray(window.SMT.catalog.products) &&
        now - (window.SMT.catalogTs || 0) < 6000
    ) {
        return Promise.resolve(window.SMT.catalog);
    }

    if (catalogPromise) {
        return catalogPromise;
    }

    if (!window.SMT?.searchUrl) {
        return Promise.resolve(null);
    }

    const url = new URL(window.SMT.searchUrl, window.location.origin);
    url.searchParams.set('catalog', '1');
    url.searchParams.set('_t', String(now));

    catalogPromise = fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' })
        .then((res) => (res.ok ? res.json() : null))
        .then((data) => {
            if (data && Array.isArray(data.products)) {
                window.SMT = window.SMT || {};
                window.SMT.catalog = data;
                window.SMT.catalogTs = Date.now();
                syncProductsWithStockStore(data.products);
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

        const liveItem = window.Alpine?.store?.('stock')?.get?.(p.id);
        const isInStock = liveItem ? Boolean(liveItem.in_stock) : Boolean(p.in_stock);
        if (isInStock) score += 10;

        scoredProducts.push({
            p: liveItem
                ? {
                      ...p,
                      in_stock: Boolean(liveItem.in_stock),
                      qty: liveItem.qty,
                      unlimited: Boolean(liveItem.unlimited),
                      price_raw: liveItem.price ?? p.price_raw,
                      price: liveItem.price_formatted ?? p.price,
                  }
                : p,
            score,
        });
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
                ensureCatalogLoaded(true);
                if (this.q.trim().length >= 1) {
                    this.queue();
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

            // Instant in-memory preview while simultaneous real-time server lookup fetches latest live stock
            const local = lookupLocalCatalog(this.term);
            if (local && local.products.length > 0) {
                this.open = true;
                this.apply(local);
            }

            this.timer = setTimeout(() => this.search(), 70);
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

            this.controller?.abort();
            this.controller = new AbortController();
            this.loading = true;

            try {
                const url = new URL(window.SMT.searchUrl, window.location.origin);
                url.searchParams.set('q', term);
                url.searchParams.set('_t', String(Date.now()));
                const res = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                    signal: this.controller.signal,
                });
                if (!res.ok) throw new Error(String(res.status));
                const data = await res.json();
                syncProductsWithStockStore(data.products);
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
        },

        filterCategoryDomNow(slug) {
            if (!targetSelector) return;
            const container = document.querySelector(targetSelector);
            if (!container) return;
            const cards = [...container.querySelectorAll('[data-category-slug]')];
            if (!cards.length) return;

            if (!slug) {
                cards.forEach((c) => (c.style.display = ''));
                return;
            }
            const matching = cards.filter((c) => c.getAttribute('data-category-slug') === slug);
            if (matching.length > 0) {
                cards.forEach((c) => {
                    c.style.display = c.getAttribute('data-category-slug') === slug ? '' : 'none';
                });
            }
        },

        setCategory(slug, event = null) {
            if (event?.currentTarget) {
                this.highlightPill(event.currentTarget);
            }
            this.filterCategoryDomNow(slug);
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

        switchUrl(rawUrl, event = null, slug = null) {
            if (event?.currentTarget) {
                this.highlightPill(event.currentTarget);
            }
            if (slug !== null) {
                this.filterCategoryDomNow(slug);
            }
            const cleanBase = String(rawUrl).split('#')[0];
            if (this.form) {
                this.form.action = cleanBase;
            }
            this.open = false;
            this.runUrl(cleanBase);
        },

        switchByEvent(detail) {
            if (!detail || !detail.url) return;
            const pill = this.$el.querySelector(`a[data-pill-slug="${detail.slug || ''}"]`);
            if (pill) {
                this.highlightPill(pill);
            }
            this.filterCategoryDomNow(detail.slug || '');
            const cleanBase = String(detail.url).split('#')[0];
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
            window.Alpine?.store?.('stock')?.poll?.(false);
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

            const controller = (this.filterController = new AbortController());
            this.loading = true;
            const currentEl = document.querySelector(targetSelector);
            if (currentEl) {
                currentEl.classList.add('opacity-55', 'pointer-events-none', 'transition-opacity', 'duration-150');
            }

            try {
                const res = await fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-SMT-Filter': '1' },
                    cache: 'no-store',
                    signal: controller.signal,
                });
                if (!res.ok) {
                    window.location.href = url;
                    return;
                }
                const html = await res.text();
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
