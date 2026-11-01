import Alpine from 'alpinejs';

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

    start(intervalMs = 10000) {
        this.poll();
        clearInterval(this.timer);
        this.timer = setInterval(() => document.hidden || this.poll(), intervalMs);

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.hiddenAt = Date.now();
            } else {
                const awayMs = this.hiddenAt ? Date.now() - this.hiddenAt : 0;
                this.hiddenAt = null;
                if (awayMs > 8000) {
                    this.stale = true;
                }
                this.poll(true);
            }
        });

        window.addEventListener('focus', () => {
            if (!document.hidden) {
                this.poll();
            }
        });
    },

    async manualSync() {
        this.syncing = true;
        try {
            if (window.SMT?.syncUrl) {
                await fetch(window.SMT.syncUrl, { method: 'POST', keepalive: true }).catch(() => {});
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
        const ids = [...new Set([...document.querySelectorAll('[data-stock-id]')].map((el) => el.dataset.stockId))];

        if (forceSupplierSync && window.SMT?.syncUrl) {
            fetch(window.SMT.syncUrl, { method: 'POST', keepalive: true }).catch(() => {});
        }

        if (!ids.length) return;

        try {
            const url = new URL(window.SMT.stockUrl);
            url.searchParams.set('ids', ids.join(','));
            const res = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (res.ok) {
                const payload = await res.json();
                const items = payload.items ?? payload;
                this.items = { ...this.items, ...items };
                if (payload.need_sync && window.SMT?.syncUrl) {
                    fetch(window.SMT.syncUrl, { method: 'POST', keepalive: true }).catch(() => {});
                }
            }
        } catch (_) {
            /* network hiccup – try again next tick */
        }
    },

    get(id) {
        return this.items[String(id)] ?? null;
    },
});

/** Product card / page / checkout stock & price badge. Initial values come from server render. */
Alpine.data('stockBadge', (id, initialQty, initialUnlimited, initialPriceFormatted = null, initialPriceNum = 0) => ({
    id: String(id),
    get data() {
        return this.$store.stock.get(this.id) ?? {
            qty: initialQty,
            unlimited: initialUnlimited,
            in_stock: initialUnlimited || initialQty > 0,
            price: initialPriceNum,
            price_formatted: initialPriceFormatted,
        };
    },
    get inStock() {
        return this.data.in_stock;
    },
    get unitPrice() {
        return Number(this.data.price ?? initialPriceNum);
    },
    get priceText() {
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
            if (saved.method && this.$el.querySelector(`input[name="payment_method_id"][value="${saved.method}"]`)) {
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
    get totalFormatted() {
        return (Math.max(1, Number(this.qty) || 1) * this.livePrice).toLocaleString();
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
 * Polls order status every 3s, drives the live verification percentage bar (0% -> 100%),
 * and reloads when status transitions (e.g. payment_review -> completed).
 */
Alpine.data('orderWatcher', (statusUrl, currentStatus) => ({
    progress: currentStatus === 'processing' ? 82 : (currentStatus === 'payment_review' ? 45 : 0),
    stageText: currentStatus === 'processing'
        ? 'Payment verified! Fetching your subscription credentials...'
        : 'Checking Transaction ID uniqueness & matching with payment gateway...',

    init() {
        const final = ['completed', 'cancelled', 'expired', 'refunded'];
        if (final.includes(currentStatus)) {
            this.progress = currentStatus === 'completed' ? 100 : 0;
            return;
        }

        if (currentStatus === 'payment_review' || currentStatus === 'processing') {
            this.animTimer = setInterval(() => {
                const cap = currentStatus === 'processing' ? 96 : 88;
                if (this.progress < cap) {
                    this.progress += 1;
                    if (this.progress > 60 && currentStatus === 'payment_review') {
                        this.stageText = 'Verifying TID with Wallet / Auto-SMS / Binance Gateway...';
                    }
                    if (this.progress > 78 && currentStatus === 'payment_review') {
                        this.stageText = 'Confirming payment receipt & preparing auto-delivery...';
                    }
                }
            }, 450);
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
                if (data.status !== currentStatus || data.expired) {
                    setTimeout(() => window.location.reload(), 250);
                }
            } catch (_) {}
        }, 3000);
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

window.Alpine = Alpine;
Alpine.start();
Alpine.store('stock').start(10000);
