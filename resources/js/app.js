import Alpine from 'alpinejs';

/**
 * Live stock: every element with [data-stock-id] is refreshed by polling /api/stock.
 * Reservations happen server-side under a row lock, so when one buyer reserves the last
 * unit every other open page shows "Out of stock" within a few seconds.
 */
Alpine.store('stock', {
    items: {},
    timer: null,

    start(intervalMs = 5000) {
        this.poll();
        clearInterval(this.timer);
        this.timer = setInterval(() => document.hidden || this.poll(), intervalMs);
    },

    async poll() {
        const ids = [...new Set([...document.querySelectorAll('[data-stock-id]')].map((el) => el.dataset.stockId))];
        if (!ids.length) return;

        try {
            const url = new URL(window.SMT.stockUrl);
            url.searchParams.set('ids', ids.join(','));
            const res = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (res.ok) this.items = { ...this.items, ...(await res.json()) };
        } catch (_) {
            /* network hiccup – try again next tick */
        }
    },

    get(id) {
        return this.items[id] ?? null;
    },
});

/** Product card / page stock & price badge. Initial values come from the server render. */
Alpine.data('stockBadge', (id, initialQty, initialUnlimited, initialPriceFormatted = null) => ({
    id: String(id),
    get data() {
        return this.$store.stock.get(this.id) ?? {
            qty: initialQty,
            unlimited: initialUnlimited,
            in_stock: initialUnlimited || initialQty > 0,
            price_formatted: initialPriceFormatted,
        };
    },
    get inStock() {
        return this.data.in_stock;
    },
    get priceText() {
        return this.data.price_formatted ?? initialPriceFormatted;
    },
    get label() {
        if (!this.data.in_stock) return 'Out of stock';
        if (this.data.unlimited) return 'In stock';
        return this.data.qty <= 5 ? `Only ${this.data.qty} left` : `${this.data.qty} in stock`;
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

/** Polls order status and reloads the page when it changes (e.g. payment approved → delivered). */
Alpine.data('orderWatcher', (statusUrl, currentStatus) => ({
    init() {
        const final = ['completed', 'cancelled', 'expired', 'refunded'];
        if (final.includes(currentStatus)) return;

        this.t = setInterval(async () => {
            if (document.hidden) return;
            try {
                const res = await fetch(statusUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                if (!res.ok) return;
                const data = await res.json();
                if (data.status !== currentStatus || data.expired) window.location.reload();
            } catch (_) {}
        }, 5000);
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
