// SellMeTools Progressive Web App Service Worker (Storefront + Admin Panel)
const SW_VERSION = 'smt-pwa-v2';

let bgConfig = {
    syncUrl: '/api/stock/sync',
    bridgeToken: '',
    seenOrderId: 0,
    seenPaymentId: 0,
    muted: false,
};

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

// Network-first pass-through to satisfy Chrome/Edge PWA installability without caching dynamic CSRF/session pages
self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }
    event.respondWith(
        fetch(event.request).catch(() => caches.match(event.request))
    );
});

async function checkOrdersFromServiceWorker() {
    if (!bgConfig.bridgeToken || bgConfig.muted) {
        return;
    }
    try {
        const res = await fetch(bgConfig.syncUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                bridge_action: 'status',
                bridge_token: bgConfig.bridgeToken,
            }),
        });
        if (!res.ok) return;
        const data = await res.json();
        if (!data || !data.ok || !data.notifications) return;

        const notif = data.notifications;
        const latestOrderId = parseInt(notif.latest_order_id || 0, 10);
        const latestPaymentId = parseInt(notif.latest_payment_id || 0, 10);

        // Check if any admin window is currently visible; if none is visible, SW fires notification directly
        const clientList = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const hasVisibleAdmin = clientList.some((c) => c.url.includes('/admin') && c.visibilityState === 'visible');

        if (bgConfig.seenOrderId > 0 && latestOrderId > bgConfig.seenOrderId) {
            bgConfig.seenOrderId = latestOrderId;
            if (!hasVisibleAdmin) {
                const newest = Array.isArray(notif.items) && notif.items.length > 0 ? notif.items[0] : null;
                if (newest) {
                    await self.registration.showNotification(
                        'New Order ' + newest.number + ' (' + newest.total + ')',
                        {
                            body: newest.product + ' · ' + newest.customer,
                            icon: '/images/pwa-icon-192.png',
                            badge: '/images/pwa-icon-192.png',
                            tag: 'smt-order-' + latestOrderId,
                            renotify: true,
                            requireInteraction: true,
                            vibrate: [250, 120, 250],
                            data: { url: newest.url || '/admin/orders' },
                        }
                    );
                }
            }
        } else if (bgConfig.seenOrderId === 0 && latestOrderId > 0) {
            bgConfig.seenOrderId = latestOrderId;
        }

        if (bgConfig.seenPaymentId > 0 && latestPaymentId > bgConfig.seenPaymentId) {
            bgConfig.seenPaymentId = latestPaymentId;
            if (!hasVisibleAdmin) {
                const reviewItem = Array.isArray(notif.items)
                    ? notif.items.find((i) => i.status === 'payment_review') || notif.items[0]
                    : null;
                if (reviewItem) {
                    await self.registration.showNotification(
                        'Payment Submitted: ' + reviewItem.number,
                        {
                            body: reviewItem.total + ' via ' + reviewItem.gateway + (reviewItem.tid ? ' (TID: ' + reviewItem.tid + ')' : ''),
                            icon: '/images/pwa-icon-192.png',
                            badge: '/images/pwa-icon-192.png',
                            tag: 'smt-payment-' + latestPaymentId,
                            renotify: true,
                            requireInteraction: true,
                            vibrate: [250, 120, 250],
                            data: { url: reviewItem.url || '/admin/orders' },
                        }
                    );
                }
            }
        } else if (bgConfig.seenPaymentId === 0 && latestPaymentId > 0) {
            bgConfig.seenPaymentId = latestPaymentId;
        }
    } catch (e) {}
}

self.addEventListener('message', (event) => {
    const msg = event.data;
    if (!msg || typeof msg !== 'object') return;

    if (msg.type === 'SMT_SYNC_CONFIG') {
        if (msg.syncUrl) bgConfig.syncUrl = msg.syncUrl;
        if (msg.bridgeToken) bgConfig.bridgeToken = msg.bridgeToken;
        if (typeof msg.seenOrderId === 'number' && msg.seenOrderId > bgConfig.seenOrderId) {
            bgConfig.seenOrderId = msg.seenOrderId;
        }
        if (typeof msg.seenPaymentId === 'number' && msg.seenPaymentId > bgConfig.seenPaymentId) {
            bgConfig.seenPaymentId = msg.seenPaymentId;
        }
        bgConfig.muted = Boolean(msg.muted);
    } else if (msg.type === 'SMT_SHOW_NOTIFICATION') {
        event.waitUntil(
            self.registration.showNotification(msg.title || 'SellMeTools Alert', {
                body: msg.body || '',
                icon: '/images/pwa-icon-192.png',
                badge: '/images/pwa-icon-192.png',
                tag: msg.tag || ('smt-alert-' + Date.now()),
                renotify: true,
                requireInteraction: true,
                vibrate: [250, 120, 250],
                data: { url: msg.url || '/admin/orders' },
            })
        );
    } else if (msg.type === 'SMT_BG_TICK') {
        event.waitUntil(checkOrdersFromServiceWorker());
    }
});

self.addEventListener('periodicsync', (event) => {
    if (event.tag === 'smt-order-check') {
        event.waitUntil(checkOrdersFromServiceWorker());
    }
});

self.addEventListener('sync', (event) => {
    if (event.tag === 'smt-order-check') {
        event.waitUntil(checkOrdersFromServiceWorker());
    }
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const targetUrl = (event.notification.data && event.notification.data.url) ? event.notification.data.url : '/admin/orders';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            for (const client of clientList) {
                if (client.url.includes('/admin') && 'focus' in client) {
                    client.navigate(targetUrl);
                    return client.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(targetUrl);
            }
        })
    );
});
