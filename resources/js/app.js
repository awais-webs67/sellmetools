import Alpine from 'alpinejs';
import { registerSearch } from './search';

registerSearch(Alpine);

/**
 * Storefront Currency Toggle (PKR <-> USDT).
 * Persists user preference in localStorage and converts any PKR amount using Admin StoreSettings usdt_rate.
 */
Alpine.store('currency', {
    current: (() => {
        try {
            const saved = localStorage.getItem('smt_currency');
            if (saved === 'USDT' || saved === 'PKR') {
                return saved;
            }
            const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
            if (tz && tz !== 'Asia/Karachi') {
                return 'USDT';
            }
            return 'PKR';
        } catch (_) {
            return 'PKR';
        }
    })(),

    get isUsdt() {
        return this.current === 'USDT';
    },

    get rate() {
        return Math.max(1, Number(window.SMT?.usdtRate || 295));
    },

    get symbol() {
        return window.SMT?.currencySymbol || 'Rs';
    },

    set(mode) {
        this.current = mode === 'USDT' ? 'USDT' : 'PKR';
        try {
            localStorage.setItem('smt_currency', this.current);
        } catch (_) {}
    },

    toggle() {
        this.set(this.current === 'PKR' ? 'USDT' : 'PKR');
    },

    format(pkrAmount, compact = false) {
        const num = Number(pkrAmount || 0);
        if (this.current === 'USDT') {
            const usdt = (num / this.rate).toFixed(2);
            return compact ? `$${usdt}` : `$${usdt} USDT`;
        }
        const rounded = Math.round(num * 100) / 100;
        const formatted = Number.isInteger(rounded)
            ? rounded.toLocaleString('en-US')
            : rounded.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return `${this.symbol} ${formatted}`;
    },
});

/**
 * Live stock & price store: every element with [data-stock-id] is refreshed by polling /api/stock.
 * Also detects when a user returns to a background tab, showing a 1-click "Sync Live Price & Stock"
 * banner that updates prices & stock in-place without clearing any form inputs!
 */
Alpine.store('stock', {
    items: {},
    timer: null,
    stale: false,
    syncing: false,
    justSynced: false,
    hiddenAt: null,
    lastPollAt: Date.now(),
    lastActiveAt: Date.now(),

    start(intervalMs = 8000) {
        const markActive = () => {
            this.lastActiveAt = Date.now();
        };
        ['pointerdown', 'keydown', 'scroll', 'touchstart', 'mousemove'].forEach((ev) =>
            window.addEventListener(ev, markActive, { passive: true })
        );

        const markHidden = () => {
            if (!this.hiddenAt) {
                this.hiddenAt = Date.now();
            }
        };

        const handleResume = (ev) => {
            if (document.hidden) return;
            const now = Date.now();
            this.lastActiveAt = now;
            const fromHidden = this.hiddenAt ? now - this.hiddenAt : 0;
            const fromLastPoll = now - (this.lastPollAt || now);
            const awayMs = Math.max(fromHidden, fromLastPoll);
            this.hiddenAt = null;

            // Show the reload/refresh popup whenever the user returns from Recent Apps / background tab after 15+ seconds or bfcache
            if (ev?.persisted || awayMs >= 15000) {
                this.stale = true;
                this.poll(true);
            } else if (awayMs >= 4000) {
                this.poll(false);
            }
        };

        // Initial background poll right after page load so prices/stock are always 100% fresh
        setTimeout(() => {
            if (!document.hidden) {
                this.poll(false);
            }
        }, 1200);

        clearInterval(this.timer);
        this.timer = setInterval(() => {
            if (document.hidden || Date.now() - this.lastActiveAt > 300000) return;
            this.poll(false);
        }, intervalMs);

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                markHidden();
            } else {
                handleResume();
            }
        });
        window.addEventListener('pagehide', markHidden, { passive: true });
        window.addEventListener('blur', markHidden, { passive: true });
        window.addEventListener('pageshow', (ev) => handleResume(ev), { passive: true });
        window.addEventListener('focus', () => handleResume(), { passive: true });
    },

    async manualSync() {
        this.syncing = true;
        try {
            if (window.SMT?.syncUrl) {
                await fetch(`${window.SMT.syncUrl}?force=1`, { method: 'POST', keepalive: true }).catch(() => {});
            }
            await this.poll(false);
            this.stale = false;
            this.justSynced = true;
            setTimeout(() => (this.justSynced = false), 3000);
        } finally {
            this.syncing = false;
        }
    },

    async poll(forceSupplierSync = false) {
        this.lastPollAt = Date.now();
        const ids = [...new Set([...document.querySelectorAll('[data-stock-id]')].map((el) => el.dataset.stockId))];

        if (forceSupplierSync && window.SMT?.syncUrl) {
            fetch(`${window.SMT.syncUrl}?force=1`, { method: 'POST', keepalive: true }).catch(() => {});
        }

        if (!ids.length) return;

        try {
            const url = new URL(window.SMT.stockUrl, window.location.origin);
            url.searchParams.set('ids', ids.join(','));
            url.searchParams.set('_t', String(Date.now()));
            const res = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (res.ok) {
                const payload = await res.json();
                const items = payload.items ?? payload;
                for (const [id, next] of Object.entries(items)) {
                    const prev = this.items[id];
                    if (
                        !prev ||
                        prev.qty !== next.qty ||
                        prev.in_stock !== next.in_stock ||
                        prev.price !== next.price ||
                        prev.retail_price !== next.retail_price ||
                        prev.unlimited !== next.unlimited
                    ) {
                        this.items[id] = next;
                    }
                }
            }
        } catch (_) {
            /* network hiccup, try again next tick */
        }
    },

    get(id) {
        return this.items[String(id)] ?? null;
    },
});

/** Product card / page / checkout stock & price badge. Initial values come from server render. */
Alpine.data('stockBadge', (id, initialQty, initialUnlimited, initialPriceFormatted = null, initialPriceNum = 0, initialRetailNum = null) => ({
    id: String(id),
    qty: 1,
    max: 10,
    walletBal: 0,
    get data() {
        return this.$store.stock.get(this.id) ?? {
            qty: initialQty,
            unlimited: initialUnlimited,
            in_stock: initialUnlimited || initialQty > 0,
            price: initialPriceNum,
            retail_price: initialRetailNum ?? initialPriceNum,
            price_formatted: initialPriceFormatted,
        };
    },
    get inStock() {
        return this.data.in_stock;
    },
    get unitPrice() {
        return Number(this.data.price ?? initialPriceNum);
    },
    get retailPrice() {
        return Number(this.data.retail_price ?? initialRetailNum ?? this.unitPrice);
    },
    get savingAmount() {
        return Math.max(0, this.retailPrice - this.unitPrice);
    },
    get savingPct() {
        return this.retailPrice > 0 && this.savingAmount > 0
            ? Math.round((this.savingAmount / this.retailPrice) * 100)
            : 0;
    },
    get priceText() {
        if (this.unitPrice > 0 && this.$store.currency) {
            return this.$store.currency.format(this.unitPrice);
        }
        return this.data.price_formatted ?? initialPriceFormatted;
    },
    get label() {
        if (!this.data.in_stock) return 'Sold Out (0)';
        if (this.data.unlimited) return 'Unlimited Stock';
        return `Stock: ${this.data.qty}`;
    },
}));

/** Checkout form controller: keeps typed user details safe in sessionStorage & syncs live price/stock. */
Alpine.data('checkoutForm', (productId, initialQty, initialPrice, initialUnlimited, initialStock, initialMethod) => ({
    id: String(productId),
    qty: Number(initialQty) || 1,
    method: String(initialMethod || ''),
    submitting: false,

    init() {
        try {
            const saved = JSON.parse(sessionStorage.getItem('smt_checkout_draft') || '{}');
            const emailEl = this.$el.querySelector('#email');
            const nameEl = this.$el.querySelector('#name');
            const phoneEl = this.$el.querySelector('#phone');

            if (emailEl && !emailEl.value && saved.email) emailEl.value = saved.email;
            if (nameEl && !nameEl.value && saved.name) nameEl.value = saved.name;
            if (phoneEl && !phoneEl.value && saved.phone) phoneEl.value = saved.phone;
            if (this.method !== 'wallet' && saved.method && this.$el.querySelector(`input[name="payment_method_id"][value="${saved.method}"]`)) {
                this.method = String(saved.method);
            }
        } catch (_) {}
    },

    saveDraft() {
        try {
            const email = this.$el.querySelector('#email')?.value || '';
            const name = this.$el.querySelector('#name')?.value || '';
            const phone = this.$el.querySelector('#phone')?.value || '';
            sessionStorage.setItem('smt_checkout_draft', JSON.stringify({
                email,
                name,
                phone,
                method: this.method,
            }));
        } catch (_) {}
    },

    get stockData() {
        return this.$store.stock.get(this.id) ?? {
            qty: initialStock,
            unlimited: initialUnlimited,
            in_stock: initialUnlimited || initialStock > 0,
            price: initialPrice,
        };
    },
    get livePrice() {
        return Number(this.stockData.price ?? initialPrice);
    },
    get inStock() {
        return this.stockData.in_stock;
    },
    get stockLabel() {
        if (!this.stockData.in_stock) return 'Sold Out (0)';
        if (this.stockData.unlimited) return 'Unlimited Stock';
        return `Stock: ${this.stockData.qty}`;
    },
    get totalAmount() {
        return Math.max(1, Number(this.qty) || 1) * this.livePrice;
    },
    get totalFormatted() {
        return this.totalAmount.toLocaleString();
    },
    get totalPrimary() {
        return this.$store.currency ? this.$store.currency.format(this.totalAmount) : `Rs ${this.totalFormatted}`;
    },
    get totalSecondary() {
        if (!this.$store.currency) return '';
        if (this.$store.currency.isUsdt) {
            return `≈ ${this.$store.currency.symbol} ${this.totalAmount.toLocaleString('en-US')} PKR`;
        }
        return `≈ $${(this.totalAmount / this.$store.currency.rate).toFixed(2)} USDT`;
    },
}));

/** Countdown until an unpaid order expires. */
Alpine.data('countdown', (expiresAtIso) => ({
    remaining: 0,
    init() {
        const end = new Date(expiresAtIso).getTime();
        const tick = () => {
            this.remaining = Math.max(0, Math.floor((end - Date.now()) / 1000));
            if (this.remaining === 0) clearInterval(this.t);
        };
        tick();
        this.t = setInterval(tick, 1000);
    },
    get text() {
        const m = String(Math.floor(this.remaining / 60)).padStart(2, '0');
        const s = String(this.remaining % 60).padStart(2, '0');
        return `${m}:${s}`;
    },
}));

/**
 * Polls order status every 3s, drives the live verification percentage bar (stops at 85% during manual review),
 * transitions to "Order On Hold" if the 15-minute review timer elapses, and reloads when status changes!
 */
Alpine.data('orderWatcher', (statusUrl, currentStatus, initialReviewTimeout = false) => ({
    progress: currentStatus === 'processing' ? 92 : (currentStatus === 'payment_review' ? 65 : 0),
    reviewTimeout: Boolean(initialReviewTimeout),
    stageText: currentStatus === 'processing'
        ? 'Payment verified! Delivering your subscription credentials...'
        : (initialReviewTimeout
            ? 'Order On Hold: Admin is checking your order. You may also contact support on WhatsApp below.'
            : 'Admin is verifying your payment, please wait...'),

    init() {
        const final = ['completed', 'cancelled', 'expired', 'refunded'];
        if (final.includes(currentStatus)) {
            this.progress = currentStatus === 'completed' ? 100 : 0;
            return;
        }

        if (currentStatus === 'payment_review' || currentStatus === 'processing') {
            this.animTimer = setInterval(() => {
                const cap = currentStatus === 'processing' ? 96 : 85;
                if (this.progress < cap) {
                    this.progress += 1;
                }
                if (currentStatus === 'payment_review' && !this.reviewTimeout && this.progress >= 85) {
                    this.stageText = 'Admin is verifying your payment, please wait (85%)...';
                }
            }, 350);
        }

        this.t = setInterval(async () => {
            if (document.hidden) return;
            try {
                const res = await fetch(statusUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                if (!res.ok) return;
                const data = await res.json();
                if (data.status === 'completed') {
                    this.progress = 100;
                    this.stageText = '100% Verified & Delivered! Loading your credentials...';
                }
                if (data.review_timeout && !this.reviewTimeout) {
                    this.reviewTimeout = true;
                    this.progress = 85;
                    this.stageText = 'Order On Hold: Admin is checking your order. You may also contact support on WhatsApp below.';
                }
                if (data.status !== currentStatus || data.expired) {
                    setTimeout(() => window.location.reload(), 250);
                }
            } catch (_) {}
        }, 5000);
    },
}));

/** Interactive percentage loader when customer submits their TID form. */
Alpine.data('paymentSubmitter', () => ({
    submitting: false,
    percent: 0,
    stepLabel: 'Validating unique Transaction ID (TID)...',

    startSubmit() {
        if (this.submitting) return false;
        this.submitting = true;
        this.percent = 18;
        const steps = [
            [38, 'Checking anti-duplicate TID database...'],
            [64, 'Connecting to Auto-Payment Verifier / Binance API...'],
            [86, 'Matching transaction amount & locking stock...'],
            [95, 'Finalizing verification...'],
        ];
        let idx = 0;
        setInterval(() => {
            if (idx < steps.length) {
                this.percent = steps[idx][0];
                this.stepLabel = steps[idx][1];
                idx++;
            }
        }, 400);
        return true;
    },
}));

/** Copy-to-clipboard button. */
Alpine.data('copy', (text) => ({
    copied: false,
    async copy() {
        try {
            await navigator.clipboard.writeText(text);
        } catch (_) {
            const ta = Object.assign(document.createElement('textarea'), { value: text });
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
        }
        this.copied = true;
        setTimeout(() => (this.copied = false), 1600);
    },
}));

/** In-place "Load More Products" grid appender so pages with 2,000+ products stay fast and compact. */
Alpine.data('loadMoreGrid', (initialNextUrl, initialShown, totalCount) => ({
    nextUrl: initialNextUrl || '',
    shown: Number(initialShown) || 0,
    total: Number(totalCount) || 0,
    loadingMore: false,

    async loadMore() {
        if (!this.nextUrl || this.loadingMore) return;
        this.loadingMore = true;

        try {
            const res = await fetch(this.nextUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) {
                window.location.href = this.nextUrl;
                return;
            }
            const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
            const regionId = this.$el.id ? `#${this.$el.id}` : '[data-load-more-region]';
            const freshRegion = doc.querySelector(regionId);
            const currentGrid = this.$el.querySelector('[data-grid-items]');
            const freshCards = freshRegion ? [...freshRegion.querySelectorAll('[data-grid-items] > *')] : [];

            if (currentGrid && freshCards.length) {
                freshCards.forEach((card) => currentGrid.appendChild(card));
                this.shown = currentGrid.children.length;
            }

            this.nextUrl = freshRegion?.getAttribute('data-next-url') || '';
        } catch (_) {
            if (this.nextUrl) window.location.href = this.nextUrl;
        } finally {
            this.loadingMore = false;
        }
    },
}));

window.Alpine = Alpine;
Alpine.start();
Alpine.store('stock').start(8000);

/**
 * Instant 0ms Tactile Click Progress Bar & Single-Link Pointerdown Prefetch.
 * Gives immediate visual feedback on every internal navigation click without hover-flooding PHP workers.
 */
(() => {
    const prefetched = new Set();
    let progressTimer = null;

    const isInternalNavLink = (a) => {
        if (!a || !a.href || a.target || a.hasAttribute('download')) return false;
        if (a.origin !== window.location.origin) return false;
        const rawHref = a.getAttribute('href') || '';
        if (rawHref.startsWith('#') || rawHref.startsWith('javascript:')) return false;
        if (a.hasAttribute('@click.prevent') || a.hasAttribute('x-on:click.prevent')) return false;
        if (a.pathname === window.location.pathname && a.search === window.location.search && a.hash) return false;
        if (/^\/(admin|logout|webhooks|quick-order|api)/.test(a.pathname)) return false;
        return true;
    };

    const showNavProgress = () => {
        const bar = document.getElementById('smt-nav-progress');
        if (!bar) return;
        clearTimeout(progressTimer);
        bar.style.opacity = '1';
        bar.style.width = '45%';
        progressTimer = setTimeout(() => {
            bar.style.width = '78%';
        }, 120);
    };

    // Prefetch ONLY the 1 link the user actually presses down on (100-180ms head start before click release!)
    const onPointerDown = (ev) => {
        const a = ev.target?.closest?.('a');
        if (!isInternalNavLink(a) || prefetched.has(a.href)) return;
        prefetched.add(a.href);
        const link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = a.href;
        link.as = 'document';
        document.head.appendChild(link);
    };

    const onClick = (ev) => {
        if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
        const a = ev.target?.closest?.('a');
        if (!isInternalNavLink(a)) return;
        if (a.pathname === window.location.pathname && a.search === window.location.search) return;
        showNavProgress();
    };

    document.addEventListener('pointerdown', onPointerDown, { passive: true });
    document.addEventListener('click', onClick, { passive: true });
    window.addEventListener('pageshow', () => {
        const bar = document.getElementById('smt-nav-progress');
        if (bar) {
            bar.style.opacity = '0';
            bar.style.width = '0%';
        }
    });
})();
