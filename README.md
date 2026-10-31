# SellMeTools

Digital tools & subscriptions store built with **Laravel 13**, **Filament 5** (admin) and **Tailwind 4 + Alpine** (storefront).

Sell your own stock (credentials, keys, links) **and** resell products from wholesale supplier APIs (Telegram shop bots) with automatic USDT → PKR conversion, profit markup, live stock and automatic delivery.

---

## Features

| Area | What it does |
|---|---|
| **Storefront** | Product catalog, categories, search, product page, checkout, order page with countdown, TID submission, auto-refreshing status, delivered credentials with copy buttons, customer accounts, order tracking, policy pages. |
| **Live stock** | Stock is reserved under a DB row lock when an order is placed – the last unit can never be sold twice. All open pages refresh stock every 5 s. |
| **Suppliers** | Pluggable drivers (`app/Services/Suppliers`). Ships with the *Standard Bot API* driver (`/products`, `/balance`, `/order`). Auto sync every minute: products, cost, stock, balance. |
| **Pricing** | Per product: fixed price, cost + amount, or cost + %. Global USDT rate & rounding. Products are auto-disabled if supplier cost rises above a fixed selling price and re-enabled when profitable again. |
| **Fulfillment** | Own stock first → cheapest profitable supplier → automatic failover to the next supplier. Idempotent `request_id`s, unknown results stop automation for manual review. |
| **Payments** | Easypaisa, JazzCash, NayaPay, SadaPay, Binance (manual verification with unique TID enforcement). Gateways can be added as new payment methods later. |
| **Admin** | `/admin` – orders, payment approval, manual delivery, retry, suppliers, supplier product import, products, own stock upload, payment methods, store settings, dashboard. |
| **Telegram** | Admin alerts: new payment to verify, order delivered with profit, order needs attention, low supplier balance, product auto-disabled. |

## Architecture

```
app/
├── Enums/                     Order / payment / stock / pricing states
├── Models/                    Eloquent models (secrets & credentials use encrypted casts)
├── Services/
│   ├── OrderService           create + reserve (row lock), release, expire
│   ├── PaymentService         submit TID, approve → fulfil, reject
│   ├── FulfillmentService     own stock → suppliers with failover, manual delivery
│   ├── PriceCalculator        cost (cheapest source) → selling price, loss guard
│   ├── InventoryService       live sellable quantity + denormalised snapshot
│   ├── SupplierSyncService    products / balance sync, listing repricing
│   ├── TelegramNotifier       admin alerts (never throws)
│   └── Suppliers/             driver contract, DTOs, exceptions, drivers
├── Filament/                  admin panel
└── Http/Controllers/          storefront
```

Adding a new supplier API format = one new class implementing `SupplierDriver` + one line in `SupplierManager::DRIVERS`.

---

## Local setup (XAMPP)

```bash
composer install
cp .env.example .env          # DB_DATABASE=sellmetools, root / no password
php artisan key:generate
php artisan migrate --seed    # creates admin + payment methods + categories
php artisan storage:link
npm install && npm run build
```

- Store: http://localhost/sellmetools/public
- Admin: http://localhost/sellmetools/public/admin – `admin@sellmetools.test` / `password` (**change immediately**)

Run the scheduler locally when testing sync/expiry:

```bash
php artisan schedule:work
# or once: php artisan suppliers:sync   |   php artisan orders:expire
```

Tests: `php artisan test`

---

## Deployment (Git → hosting)

Built frontend assets (`public/build`) are committed, so the server does **not** need Node.js.

### First deploy

```bash
git clone https://github.com/awais-webs67/sellmetools.git
cd sellmetools
composer install --no-dev --optimize-autoloader
cp .env.example .env
# edit .env: APP_ENV=production, APP_DEBUG=false, APP_URL=https://yourdomain.com, DB_* credentials
php artisan key:generate
php artisan migrate --force --seed
php artisan storage:link
php artisan optimize
```

Point the domain's **document root to `/public`**. If your host does not allow that, the root `.htaccess` forwards everything to `/public` automatically.

### Cron (required – one entry runs everything)

```
* * * * * cd /path/to/sellmetools && php artisan schedule:run >> /dev/null 2>&1
```

This runs supplier sync and unpaid-order expiry every minute. No queue worker is required.

### Updating

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
```

---

## First-time configuration (admin)

1. **Settings → Store settings**: store name, USDT rate, Telegram bot token + your chat ID (send a test message), support contacts.
2. **Settings → Payment methods**: enter your real Easypaisa / JazzCash / NayaPay / SadaPay account numbers and Binance Pay ID.
3. **Suppliers → Suppliers**: add a supplier (base URL + API key) → *Sync now*.
4. **Suppliers → Supplier products**: *Create listing* with your price rule.
5. Own stock: **Catalog → Products → Stock items → Bulk add stock** (one item per line).

## Security notes

- Supplier API keys, own stock and delivered credentials are encrypted at rest (`APP_KEY` – back it up; losing it makes them unreadable).
- Guest order pages require a 48-character secret token; logged-in customers only see their own orders.
- Transaction IDs are unique per payment method; checkout, payment and lookup endpoints are rate limited.

## Roadmap

- Auto payment verification (SMS forwarder webhook for wallets, Binance API)
- Telegram approve/reject buttons and customer bot
- WebSocket push (Pusher/Reverb) in addition to polling
- Customer wallet & deposits
