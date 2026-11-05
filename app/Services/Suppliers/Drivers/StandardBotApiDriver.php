<?php

namespace App\Services\Suppliers\Drivers;

use App\Models\Setting;
use App\Models\Supplier;
use App\Services\Suppliers\Contracts\SupplierDriver;
use App\Services\Suppliers\Data\SupplierBalance;
use App\Services\Suppliers\Data\SupplierOrderResult;
use App\Services\Suppliers\Data\SupplierProductData;
use App\Services\Suppliers\Exceptions\SupplierAuthException;
use App\Services\Suppliers\Exceptions\SupplierBridgePendingException;
use App\Services\Suppliers\Exceptions\SupplierException;
use App\Services\Suppliers\Exceptions\SupplierInsufficientBalanceException;
use App\Services\Suppliers\Exceptions\SupplierOutOfStockException;
use App\Services\Suppliers\Exceptions\SupplierUnknownResultException;
use App\Support\TextCleaner;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Universal driver supporting:
 * - Standard Telegram Shop Bot API (/products, /balance, /order)
 * - ProdSeller (/v1/products with inStock, /v1/balance, /v1/orders with deliveredKey/deliveredKeys)
 * - BunaiStore (/v1/products with stock_count & infinite_stock, /v1/me, /v1/orders)
 * - Qamify (/v1/products with unit_price, /v1/balance, /v1/orders with items[].content)
 * - TunvnMMO (/api/products with price_usdt, /api/balance with balance_usdt, /api/buy)
 * - CanBoSo (/api/v2/telegram-buyer/products, /balance, /purchase with delivery.accounts)
 * - EliteToolz (/api/products, /api/balance, /api/order)
 */
class StandardBotApiDriver implements SupplierDriver
{
    public function __construct(protected Supplier $supplier) {}

    protected function defaultHeaders(): array
    {
        $key = trim((string) $this->supplier->api_key);

        return [
            'X-API-Key' => $key,
            'x-buyer-key' => $key,
            'Authorization' => 'Bearer '.$key,
            'User-Agent' => 'SellMeTools/2.0 (+https://sellmetools.com)',
        ];
    }

    protected function normalizedBaseUrl(): string
    {
        $url = rtrim(trim((string) $this->supplier->base_url), '/');
        $lower = strtolower($url);
        $apiKey = (string) $this->supplier->api_key;

        if ((str_contains($lower, 'canboso.com') || str_starts_with($apiKey, 'tgb_')) && ! str_ends_with($lower, '/api/v2/telegram-buyer')) {
            return 'https://canboso.com/api/v2/telegram-buyer';
        }

        if ((str_contains($lower, 'bunaistore.shop') || str_starts_with($apiKey, 'shop:')) && ! str_ends_with($lower, '/v1')) {
            return 'https://api.bunaistore.shop/v1';
        }

        if ((str_contains($lower, 'prodseller.com') || str_starts_with($apiKey, 'psk_')) && ! str_ends_with($lower, '/v1')) {
            return 'https://prodseller.com/v1';
        }

        if ((str_contains($lower, 'qamify.site') || str_starts_with($apiKey, 'qamify_')) && ! str_ends_with($lower, '/v1')) {
            return 'https://api.qamify.site/v1';
        }

        return $url;
    }

    protected function productsEndpoint(): string
    {
        $baseUrl = strtolower($this->normalizedBaseUrl());
        if (str_contains($baseUrl, 'bunaistore')) {
            return '/products?include_note=true&limit=100';
        }

        return '/products';
    }

    protected function balanceEndpoint(): string
    {
        $baseUrl = strtolower($this->normalizedBaseUrl());
        $apiKey = (string) $this->supplier->api_key;
        if (str_contains($baseUrl, 'bunaistore') || str_starts_with($apiKey, 'shop:')) {
            return '/me';
        }

        return '/balance';
    }

    public function isRailwayHost(): bool
    {
        $host = strtolower((string) parse_url($this->normalizedBaseUrl(), PHP_URL_HOST));

        return str_ends_with($host, '.up.railway.app') || str_contains($host, 'elitetoolz.store');
    }

    public function getNormalizedBaseUrl(): string
    {
        return $this->normalizedBaseUrl();
    }

    public function getProductsEndpoint(): string
    {
        return $this->productsEndpoint();
    }

    public function getBalanceEndpoint(): string
    {
        return $this->balanceEndpoint();
    }

    protected function curlOptions(?string $overrideIp = null): array
    {
        $opts = [
            'verify' => false,
            'force_ip_resolve' => 'v4',
        ];

        $host = parse_url($this->normalizedBaseUrl(), PHP_URL_HOST);
        $targetIp = $overrideIp;
        if (! $targetIp && is_string($host) && str_ends_with(strtolower($host), '.up.railway.app')) {
            $edges = ['69.46.46.88', '69.46.46.90', '69.46.46.95', '69.46.46.97'];
            $targetIp = $edges[array_rand($edges)];
        }

        if ($targetIp && is_string($host) && defined('CURLOPT_RESOLVE')) {
            $opts['curl'] = [
                CURLOPT_RESOLVE => [
                    "{$host}:443:{$targetIp}",
                ],
            ];
        }

        return $opts;
    }

    protected function http(int $timeout = 25, int $connectTimeout = 15): PendingRequest
    {
        return Http::baseUrl($this->normalizedBaseUrl())
            ->withOptions($this->curlOptions())
            ->withHeaders($this->defaultHeaders())
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout($connectTimeout);
    }

    public function cloudRelayUrl(): string
    {
        try {
            return trim((string) Setting::get('supplier_relay_url', ''));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Routes a supplier API request through the configured 24/7 Cloud Relay (Google Apps Script / Cloudflare Worker)
     * so Hostinger's server can communicate with Railway-hosted suppliers (Safwan, Elite Toolz) server-to-server in < 500ms.
     */
    public function sendViaCloudRelay(string $method, string $path, ?array $payload = null, int $timeout = 25): Response
    {
        $relayUrl = $this->cloudRelayUrl();
        if ($relayUrl === '') {
            throw new ConnectionException('No supplier cloud relay URL configured.');
        }

        $targetUrl = rtrim($this->normalizedBaseUrl(), '/').'/'.ltrim($path, '/');
        $headers = array_merge($this->defaultHeaders(), [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);

        $relayResponse = Http::withOptions(['verify' => false])
            ->acceptJson()
            ->connectTimeout(6)
            ->timeout($timeout)
            ->post($relayUrl, [
                'url' => $targetUrl,
                'method' => strtoupper($method),
                'headers' => $headers,
                'body' => $payload,
            ]);

        if (! $relayResponse->successful()) {
            throw new ConnectionException('Cloud relay HTTP '.$relayResponse->status());
        }

        $envelope = $relayResponse->json();
        if (is_array($envelope) && array_key_exists('status', $envelope) && array_key_exists('body', $envelope)) {
            $innerStatus = (int) ($envelope['status'] ?: 200);
            $innerBody = is_string($envelope['body'])
                ? $envelope['body']
                : (string) json_encode($envelope['body'] ?? []);

            return new Response(new Psr7Response($innerStatus, ['Content-Type' => 'application/json'], $innerBody));
        }

        return $relayResponse;
    }

    /**
     * Fetches /products and /balance in parallel using Http::pool, with automatic sequential retry if pool connection times out.
     *
     * @return array{products: SupplierProductData[], balance: ?SupplierBalance}
     */
    public function productsAndBalance(): array
    {
        $baseUrl = $this->normalizedBaseUrl();
        $headers = $this->defaultHeaders();
        $productsPath = $this->productsEndpoint();
        $balancePath = $this->balanceEndpoint();

        if (! app()->runningUnitTests() && $this->isRailwayHost()) {
            if ($this->cloudRelayUrl() !== '') {
                try {
                    $prodResponse = $this->sendViaCloudRelay('GET', $productsPath, null, 15);
                    $this->guard($prodResponse);
                    $products = $this->parseProductsResponse($prodResponse);

                    $balanceObj = $this->extractBalanceFromResponse($prodResponse);
                    $balJson = null;
                    if ($balanceObj === null) {
                        try {
                            $balResponse = $this->sendViaCloudRelay('GET', $balancePath, null, 10);
                            $balJson = $balResponse->json();
                            $balanceObj = $this->extractBalanceFromResponse($balResponse);
                        } catch (\Throwable) {
                            // Use existing balance if balance endpoint fails
                        }
                    }

                    Cache::put('supplier_raw_cache_'.$this->supplier->id, [
                        'products' => $prodResponse->json(),
                        'balance' => $balJson ?? $prodResponse->json(),
                        'at' => now()->timestamp,
                    ], 86400);

                    return [
                        'products' => $products,
                        'balance' => $balanceObj,
                    ];
                } catch (\Throwable) {
                    // Fall back immediately to cached catalog + browser bridge sync below
                }
            }

            Cache::put('supplier_needs_bridge_sync_'.$this->supplier->id, true, 600);

            return $this->railwayFallbackProductsAndBalance();
        }

        $options = $this->curlOptions();

        $responses = Http::pool(fn (Pool $pool) => [
            $pool->as('products')->baseUrl($baseUrl)->withOptions($options)->withHeaders($headers)->acceptJson()->connectTimeout(15)->timeout(25)->get($productsPath),
            $pool->as('balance')->baseUrl($baseUrl)->withOptions($options)->withHeaders($headers)->acceptJson()->connectTimeout(15)->timeout(15)->get($balancePath),
        ]);

        $prodResponse = $responses['products'] ?? null;
        $balResponse = $responses['balance'] ?? null;

        // If parallel pool failed to connect (e.g. Railway cold start), retry /products sequentially with a generous timeout
        if (! $prodResponse instanceof Response) {
            try {
                $prodResponse = $this->http(30)->get($productsPath);
            } catch (ConnectionException $e) {
                throw new SupplierException('Could not connect to supplier /products endpoint: '.$e->getMessage(), previous: $e);
            }
        }

        $this->guard($prodResponse);

        $products = $this->parseProductsResponse($prodResponse);

        $balanceObj = $this->extractBalanceFromResponse($balResponse)
            ?? $this->extractBalanceFromResponse($prodResponse);

        // Fallback across common balance/profile endpoints if primary balance endpoint failed or didn't include balance
        if ($balanceObj === null) {
            foreach (['/me', '/developer/me', '/wallet', '/user', '/profile'] as $altPath) {
                if ($altPath === $balancePath && $balResponse instanceof Response && $balResponse->successful()) {
                    continue;
                }
                try {
                    $altResponse = $this->http(10)->get($altPath);
                    $balanceObj = $this->extractBalanceFromResponse($altResponse);
                    if ($balanceObj !== null) {
                        break;
                    }
                } catch (\Throwable) {
                    // Try next fallback endpoint
                }
            }
        }

        return [
            'products' => $products,
            'balance' => $balanceObj,
        ];
    }

    /**
     * @return array{products: SupplierProductData[], balance: ?SupplierBalance}
     */
    protected function railwayFallbackProductsAndBalance(?\Throwable $e = null): array
    {
        $cached = Cache::get('supplier_raw_cache_'.$this->supplier->id);
        if (is_array($cached) && is_array($cached['products'] ?? null)) {
            $products = $this->parseProductsPayloadArray($cached['products']);
            $balanceObj = is_array($cached['balance'] ?? null)
                ? $this->extractBalanceFromPayloadArray($cached['balance'])
                : null;
            if ($balanceObj === null && $this->supplier->balance !== null) {
                $balanceObj = new SupplierBalance((float) $this->supplier->balance, (string) $this->supplier->currency, ['cached' => true]);
            }

            return [
                'products' => $products,
                'balance' => $balanceObj,
            ];
        }

        $existingProducts = $this->supplier->products()->get();
        if ($existingProducts->isNotEmpty()) {
            $products = $existingProducts->map(fn ($sp) => new SupplierProductData(
                externalId: (string) $sp->external_id,
                name: (string) $sp->name,
                cost: (float) $sp->cost,
                stock: $sp->stock !== null ? (int) $sp->stock : null,
                unlimitedStock: (bool) $sp->unlimited_stock,
                available: (bool) $sp->is_available,
                warranty: $sp->warranty,
                description: $sp->description,
                raw: is_array($sp->raw) ? $sp->raw : [],
            ))->values()->all();

            $balanceObj = $this->supplier->balance !== null
                ? new SupplierBalance((float) $this->supplier->balance, (string) $this->supplier->currency, ['cached' => true])
                : null;

            return [
                'products' => $products,
                'balance' => $balanceObj,
            ];
        }

        throw new SupplierException('Could not connect to supplier /products endpoint: '.($e?->getMessage() ?? 'Railway edge cooldown active.'), previous: $e);
    }

    protected function parseNumericValue(mixed $val): ?float
    {
        if (is_int($val) || is_float($val)) {
            return (float) $val;
        }

        if (is_string($val)) {
            $trimmed = trim($val);
            if ($trimmed === '') {
                return null;
            }
            if (is_numeric($trimmed)) {
                return (float) $trimmed;
            }
            $normalized = str_replace(',', '', $trimmed);
            if (preg_match('/(-?\d+(?:\.\d+)?)/', $normalized, $m)) {
                return (float) $m[1];
            }
        }

        if (is_array($val)) {
            foreach (['balance_usdt', 'usdt', 'usd', 'amount', 'balance', 'available', 'value', 'total', 'text'] as $subKey) {
                if (array_key_exists($subKey, $val)) {
                    $parsed = $this->parseNumericValue($val[$subKey]);
                    if ($parsed !== null) {
                        return $parsed;
                    }
                }
            }
        }

        return null;
    }

    protected function extractBalanceFromResponse(?Response $response): ?SupplierBalance
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return null;
        }

        return $this->extractBalanceFromPayloadArray($payload);
    }

    public function extractBalanceFromPayloadArray(array $payload): ?SupplierBalance
    {
        if (($payload['ok'] ?? null) === false || ($payload['success'] ?? null) === false) {
            return null;
        }

        $candidatePaths = [
            'balance_usdt',
            'usdtBalance',
            'balanceUsd',
            'balance_usd',
            'usd_balance',
            'usdt_balance',
            'balance',
            'wallet_balance',
            'walletBalance',
            'available_balance',
            'availableBalance',
            'user_balance',
            'userBalance',
            'account_balance',
            'data.balance_usdt',
            'data.usdtBalance',
            'data.balanceUsd',
            'data.balance',
            'data.wallet_balance',
            'data.wallet',
            'data.user.balance',
            'data.user.wallet_balance',
            'user.balance_usdt',
            'user.balance',
            'user.wallet_balance',
            'user.wallet',
            'wallet.balance',
            'wallet.amount',
            'wallet.usdt',
            'wallet',
            'account.balance',
            'profile.balance',
            'reseller.balance',
            'requester.balance',
            'credits',
            'credit',
            'funds',
            'balanceText',
        ];

        $foundValues = [];
        foreach ($candidatePaths as $path) {
            $raw = Arr::get($payload, $path);
            if ($raw === null) {
                continue;
            }
            $parsed = $this->parseNumericValue($raw);
            if ($parsed !== null) {
                $foundValues[] = $parsed;
            }
        }

        $balanceCents = Arr::get($payload, 'balance_cents');
        if ($foundValues === [] && is_numeric($balanceCents)) {
            $foundValues[] = ((float) $balanceCents) / 100.0;
        }

        if ($foundValues === []) {
            return null;
        }

        // Prefer a positive (> 0) balance if multiple fields exist (e.g., balance: 0 vs balance_usdt: 1.50)
        $positive = array_values(array_filter($foundValues, fn (float $v): bool => $v > 0));
        $finalBalance = $positive !== [] ? $positive[0] : $foundValues[0];

        return new SupplierBalance(
            balance: $finalBalance,
            currency: (string) (Arr::get($payload, 'currency') ?? Arr::get($payload, 'walletCurrency') ?? Arr::get($payload, 'data.currency') ?? $this->supplier->currency),
            raw: $payload,
        );
    }

    public function products(): array
    {
        if (! app()->runningUnitTests() && $this->isRailwayHost()) {
            return $this->productsAndBalance()['products'];
        }

        $response = $this->send(fn () => $this->http(25)->get($this->productsEndpoint()));
        $this->guard($response);

        return $this->parseProductsResponse($response);
    }

    /**
     * @return SupplierProductData[]
     */
    protected function parseProductsResponse(Response $response): array
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new SupplierException('Unexpected products response format.');
        }

        return $this->parseProductsPayloadArray($payload);
    }

    /**
     * @return SupplierProductData[]
     */
    public function parseProductsPayloadArray(array $payload): array
    {
        $rows = Arr::get($payload, 'products')
            ?? Arr::get($payload, 'data.products')
            ?? Arr::get($payload, 'items')
            ?? Arr::get($payload, 'data.items')
            ?? Arr::get($payload, 'variants')
            ?? (array_is_list($payload) ? $payload : null);

        if (! is_array($rows)) {
            throw new SupplierException('Unexpected products response format.');
        }

        return array_values(array_filter(array_map(function ($row) {
            if (! is_array($row)) {
                return null;
            }

            $id = $row['id'] ?? $row['product_id'] ?? $row['productId'] ?? $row['code'] ?? null;
            $name = $row['name'] ?? $row['display_name'] ?? $row['title'] ?? $row['product_name'] ?? null;
            $rawPrice = $row['price'] ?? $row['unit_price'] ?? $row['price_usdt'] ?? $row['finalPrice'] ?? $row['cost'] ?? null;
            if (is_array($rawPrice)) {
                $rawPrice = $rawPrice['amount'] ?? $rawPrice['value'] ?? $rawPrice['usd'] ?? null;
            }

            if ($id === null || $name === null || $rawPrice === null || ! is_numeric($rawPrice)) {
                return null;
            }

            $unlimited = (bool) ($row['unlimited_stock'] ?? $row['stock_unlimited'] ?? $row['infinite_stock'] ?? false);
            $rawStock = $row['stock']
                ?? $row['stock_count']
                ?? $row['quantity']
                ?? $row['available_stock']
                ?? (is_array($row['availability'] ?? null) ? ($row['availability']['available'] ?? null) : null);
            $stock = $rawStock !== null && is_numeric($rawStock) ? (int) $rawStock : null;

            $explicitAvailable = $row['available'] ?? $row['is_available'] ?? $row['inStock'] ?? $row['in_stock'] ?? null;

            // A null stock without the unlimited flag is treated as unlimited when explicitly marked available/inStock (e.g. ProdSeller).
            if ($stock === null && ! $unlimited && $explicitAvailable === true) {
                $unlimited = true;
            }
            if ($stock === null && ! $unlimited && $explicitAvailable === false) {
                $stock = 0;
            }

            $warranty = $row['warranty'] ?? $row['warranty_period'] ?? $row['subscription_type'] ?? null;
            if ($warranty === null && isset($row['warranty_hours']) && is_numeric($row['warranty_hours']) && (int) $row['warranty_hours'] > 0) {
                $hours = (int) $row['warranty_hours'];
                $warranty = ($hours >= 24 && $hours % 24 === 0) ? ($hours / 24).' days' : $hours.' hours';
            }

            $description = $row['description'] ?? $row['instructions'] ?? $row['note'] ?? null;

            return new SupplierProductData(
                externalId: (string) $id,
                name: TextCleaner::clean((string) $name),
                cost: (float) $rawPrice,
                stock: $stock,
                unlimitedStock: $unlimited,
                available: (bool) ($explicitAvailable ?? ($unlimited || $stock > 0)),
                warranty: TextCleaner::clean(is_scalar($warranty) ? (string) $warranty : null),
                description: TextCleaner::clean(is_scalar($description) ? (string) $description : null),
                raw: $row,
            );
        }, $rows)));
    }

    public function balance(): SupplierBalance
    {
        $primaryPath = $this->balanceEndpoint();

        if (! app()->runningUnitTests() && $this->isRailwayHost()) {
            if ($this->cloudRelayUrl() !== '') {
                try {
                    $response = $this->sendViaCloudRelay('GET', $primaryPath, null, 10);
                    $extracted = $this->extractBalanceFromResponse($response);
                    if ($extracted !== null) {
                        return $extracted;
                    }
                } catch (\Throwable) {
                    // Fall back immediately to cached balance + browser bridge sync
                }
            }

            Cache::put('supplier_needs_bridge_sync_'.$this->supplier->id, true, 600);
            $cached = Cache::get('supplier_raw_cache_'.$this->supplier->id);
            if (is_array($cached) && is_array($cached['balance'] ?? null)) {
                $ext = $this->extractBalanceFromPayloadArray($cached['balance']);
                if ($ext !== null) {
                    return $ext;
                }
            }

            return new SupplierBalance(
                balance: (float) ($this->supplier->balance ?? 0.0),
                currency: (string) ($this->supplier->currency ?: 'USDT'),
                raw: ['cached' => true],
            );
        }

        $response = $this->send(fn () => $this->http(15)->get($primaryPath));

        $extracted = $this->extractBalanceFromResponse($response);
        if ($extracted !== null) {
            return $extracted;
        }

        if (in_array($response->status(), [401, 403], true)) {
            $this->guard($response);
        }

        foreach (['/me', '/developer/me', '/wallet', '/user', '/profile', '/products'] as $altPath) {
            if ($altPath === $primaryPath) {
                continue;
            }
            try {
                $altResponse = $this->http(12)->get($altPath);
                $altExtracted = $this->extractBalanceFromResponse($altResponse);
                if ($altExtracted !== null) {
                    return $altExtracted;
                }
            } catch (\Throwable) {
                // Continue trying fallback balance paths
            }
        }

        $this->guard($response);

        return new SupplierBalance(
            balance: 0.0,
            currency: (string) ($response->json('currency') ?? $response->json('walletCurrency') ?? $response->json('data.currency') ?? $this->supplier->currency),
            raw: $response->json() ?? [],
        );
    }

    public function placeOrder(string $externalProductId, int $quantity, string $requestId): SupplierOrderResult
    {
        $baseUrl = strtolower((string) $this->supplier->base_url);
        $apiKey = (string) $this->supplier->api_key;
        $normalizedId = is_numeric($externalProductId) ? (int) $externalProductId : $externalProductId;
        $idempotencyKey = strlen($requestId) >= 8 ? substr($requestId, 0, 100) : 'ord-'.str_pad($requestId, 8, '0');

        $isProdSeller = str_contains($baseUrl, 'prodseller') || str_starts_with($apiKey, 'psk_');
        $isBunai = str_contains($baseUrl, 'bunaistore') || str_starts_with($apiKey, 'shop:');
        $isQamify = str_contains($baseUrl, 'qamify') || str_starts_with($apiKey, 'qamify_');
        $isTunvn = str_contains($baseUrl, 'tunvnmmo');
        $isCanboso = str_contains($baseUrl, 'canboso') || str_starts_with($apiKey, 'tgb_');
        $isRailway = ! app()->runningUnitTests() && $this->isRailwayHost();

        if ($isRailway) {
            if ($this->cloudRelayUrl() !== '') {
                try {
                    $relayResp = $this->sendViaCloudRelay('POST', '/order', [
                        'product_id' => $normalizedId,
                        'productId' => (string) $externalProductId,
                        'quantity' => $quantity,
                        'request_id' => $requestId,
                    ], 30);

                    if ($relayResp->status() === 404) {
                        $relayResp = $this->sendViaCloudRelay('POST', '/orders', [
                            'product_id' => $normalizedId,
                            'productId' => (string) $externalProductId,
                            'qty' => $quantity,
                            'quantity' => $quantity,
                        ], 30);
                    }

                    if ($relayResp->serverError()) {
                        throw SupplierUnknownResultException::withContext('Supplier server error '.$relayResp->status(), ['body' => $relayResp->body()]);
                    }

                    $this->guard($relayResp);

                    return $this->parseOrderPayloadArray($relayResp->status(), $relayResp->json() ?? []);
                } catch (ConnectionException) {
                    // If cloud relay is unreachable, hand off immediately to the live browser bridge in 0ms
                }
            }

            throw SupplierBridgePendingException::withContext('Dispatching via live supplier bridge...');
        }

        $connTimeout = 15;

        $executeOrderRequest = function () use ($isProdSeller, $isBunai, $isQamify, $isTunvn, $isCanboso, $externalProductId, $normalizedId, $quantity, $requestId, $idempotencyKey, $apiKey, $connTimeout): Response {
            if ($isProdSeller) {
                return $this->http(60, $connTimeout)
                    ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                    ->post('/orders', [
                        'productId' => (string) $externalProductId,
                        'quantity' => $quantity,
                    ]);
            }
            if ($isBunai) {
                return $this->http(60, $connTimeout)
                    ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                    ->post('/orders', [
                        'product_id' => (string) $externalProductId,
                        'qty' => $quantity,
                        'include_after_note' => true,
                    ]);
            }
            if ($isQamify) {
                return $this->http(60, $connTimeout)
                    ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                    ->post('/orders', [
                        'product_id' => $normalizedId,
                        'qty' => $quantity,
                    ]);
            }
            if ($isTunvn) {
                return $this->http(60, $connTimeout)->post('/buy', [
                    'product_id' => $normalizedId,
                    'quantity' => $quantity,
                    'currency' => strtolower($this->supplier->currency ?: 'usdt'),
                ]);
            }
            if ($isCanboso) {
                return $this->http(60, $connTimeout)
                    ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                    ->post('/purchase', [
                        'key' => $apiKey,
                        'product_id' => (string) $externalProductId,
                        'quantity' => $quantity,
                    ]);
            }

            // Default / Standard Bot / EliteToolz
            $resp = $this->http(60, $connTimeout)->post('/order', [
                'product_id' => $normalizedId,
                'productId' => (string) $externalProductId,
                'quantity' => $quantity,
                'request_id' => $requestId,
            ]);

            // Safe 404 fallback (route does not exist -> nothing was charged)
            if ($resp->status() === 404) {
                $resp = $this->http(60, $connTimeout)
                    ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                    ->post('/orders', [
                        'product_id' => $normalizedId,
                        'productId' => (string) $externalProductId,
                        'qty' => $quantity,
                        'quantity' => $quantity,
                    ]);
            }

            return $resp;
        };

        try {
            $response = $executeOrderRequest();
        } catch (ConnectionException $e) {
            $errLower = Str::lower($e->getMessage());
            $isConnectPhaseTimeout = Str::contains($errLower, [
                'connection timed out',
                'failed to connect',
                'could not resolve host',
                'ssl connection timeout',
            ]);

            if ($isConnectPhaseTimeout) {
                if ($isRailway) {
                    Cache::put('railway_direct_cooldown_'.$this->supplier->id, true, 86400);
                    throw SupplierException::withContext('Could not connect directly to Railway edge (queued for instant browser bridge / alternate supplier).');
                }
                if (! app()->runningUnitTests()) {
                    usleep(800000);
                    try {
                        $response = $executeOrderRequest();
                    } catch (ConnectionException $retryEx) {
                        $retryLower = Str::lower($retryEx->getMessage());
                        if (Str::contains($retryLower, ['connection timed out', 'failed to connect', 'could not resolve host', 'ssl connection timeout'])) {
                            throw SupplierException::withContext('Could not connect to supplier while placing order: '.$retryEx->getMessage());
                        }
                        throw SupplierUnknownResultException::withContext('Connection error while placing order: '.$retryEx->getMessage());
                    }
                } else {
                    throw SupplierException::withContext('Could not connect to supplier while placing order: '.$e->getMessage());
                }
            } else {
                throw SupplierUnknownResultException::withContext('Connection error while placing order: '.$e->getMessage());
            }
        }

        if ($response->serverError()) {
            throw SupplierUnknownResultException::withContext('Supplier server error '.$response->status(), ['body' => $response->body()]);
        }

        $this->guard($response);

        return $this->parseOrderPayloadArray($response->status(), $response->json() ?? []);
    }

    public function parseOrderPayloadArray(int $httpStatus, array $payload): SupplierOrderResult
    {
        if ($httpStatus >= 500) {
            throw SupplierUnknownResultException::withContext('Supplier server error '.$httpStatus, ['body' => $payload]);
        }

        $failed = $httpStatus >= 400 || ($payload['ok'] ?? null) === false || ($payload['success'] ?? null) === false;
        if ($failed) {
            $rawErr = Arr::get($payload, 'error.message')
                ?? Arr::get($payload, 'error')
                ?? Arr::get($payload, 'message')
                ?? Arr::get($payload, 'detail');
            $message = is_scalar($rawErr) ? (string) $rawErr : (is_array($rawErr) ? (string) json_encode($rawErr) : '');
            $lower = Str::lower($message);

            if (in_array($httpStatus, [401, 403], true)) {
                throw SupplierAuthException::withContext($message ?: 'Invalid API key.');
            }
            if ($httpStatus === 402 || Str::contains($lower, ['insufficient', 'balance', 'not enough', 'solde insuffisant'])) {
                throw SupplierInsufficientBalanceException::withContext($message ?: 'Insufficient balance.');
            }
            if ($httpStatus === 409 || Str::contains($lower, ['out of stock', 'no stock', 'unavailable', 'sold out', 'inventory not enough'])) {
                throw SupplierOutOfStockException::withContext($message ?: 'Out of stock.');
            }
            throw SupplierException::withContext($message ?: 'Supplier request failed with HTTP '.$httpStatus, ['status' => $httpStatus, 'body' => $payload]);
        }

        $order = is_array($payload['order'] ?? null) ? $payload['order'] : (is_array($payload['data'] ?? null) ? $payload['data'] : []);
        $rawItems = $order['items']
            ?? $order['delivered_items']
            ?? $order['delivery_items']
            ?? $order['deliveryItems']
            ?? $order['deliveredKeys']
            ?? $order['deliveredKey']
            ?? $order['accounts']
            ?? $order['credentials']
            ?? $order['codes']
            ?? $order['deliveries']
            ?? $order['content']
            ?? Arr::get($payload, 'deliveredKeys')
            ?? Arr::get($payload, 'deliveredKey')
            ?? Arr::get($payload, 'delivered_items')
            ?? Arr::get($payload, 'delivery_items')
            ?? Arr::get($payload, 'deliveryItems')
            ?? Arr::get($payload, 'items')
            ?? Arr::get($payload, 'accounts')
            ?? Arr::get($payload, 'credentials')
            ?? Arr::get($payload, 'codes')
            ?? Arr::get($payload, 'content')
            ?? Arr::get($payload, 'delivery.accounts')
            ?? Arr::get($payload, 'delivery.items')
            ?? Arr::get($payload, 'data.items')
            ?? Arr::get($payload, 'data.delivery_items')
            ?? Arr::get($payload, 'data.delivered_items')
            ?? Arr::get($payload, 'data.accounts')
            ?? [];

        if (is_string($rawItems) && trim($rawItems) !== '') {
            $rawItems = [trim($rawItems)];
        }

        $items = is_array($rawItems)
            ? array_values(array_filter(
                array_map(function ($v): string {
                    if (is_scalar($v)) {
                        return trim((string) $v);
                    }
                    if (is_array($v)) {
                        if (isset($v['user']) || isset($v['password'])) {
                            $parts = [trim((string) ($v['user'] ?? '')).':'.trim((string) ($v['password'] ?? ''))];
                            if (! empty($v['verifyEmail'])) {
                                $parts[] = 'Verify: '.trim((string) $v['verifyEmail']);
                            }
                            if (! empty($v['expiryText'])) {
                                $parts[] = 'Expiry: '.trim((string) $v['expiryText']);
                            }
                            if (! empty($v['otherInfo'])) {
                                $parts[] = 'Info: '.trim((string) $v['otherInfo']);
                            }

                            return implode(' | ', $parts);
                        }
                        foreach (['content', 'account', 'credential', 'code', 'key', 'data', 'value', 'text'] as $k) {
                            if (isset($v[$k]) && is_scalar($v[$k]) && trim((string) $v[$k]) !== '') {
                                return trim((string) $v[$k]);
                            }
                        }
                    }

                    return '';
                }, $rawItems),
                fn (string $v): bool => $v !== ''
            ))
            : [];

        if ($items === []) {
            throw SupplierUnknownResultException::withContext('Order accepted by supplier but returned empty credentials.', ['body' => $payload]);
        }

        $extOrderId = $order['order_id']
            ?? $order['orderId']
            ?? $order['order_group']
            ?? $order['orderCode']
            ?? $order['code']
            ?? $order['id']
            ?? Arr::get($payload, 'orderId')
            ?? Arr::get($payload, 'order_id')
            ?? Arr::get($payload, 'order.orderCode')
            ?? Arr::get($payload, 'code')
            ?? Arr::get($payload, 'id')
            ?? null;

        $totalCost = $order['total']
            ?? $order['total_paid']
            ?? $order['total_price']
            ?? $order['total_usdt']
            ?? $order['amount']
            ?? $order['price']
            ?? Arr::get($payload, 'amount')
            ?? Arr::get($payload, 'total')
            ?? Arr::get($payload, 'total_paid')
            ?? Arr::get($payload, 'total_price')
            ?? Arr::get($payload, 'total_usdt')
            ?? Arr::get($payload, 'payment.amount')
            ?? Arr::get($payload, 'price')
            ?? null;

        $balAfter = $order['balance_after']
            ?? $order['new_balance']
            ?? Arr::get($payload, 'balance_after')
            ?? Arr::get($payload, 'new_balance')
            ?? Arr::get($payload, 'reseller.balance')
            ?? Arr::get($payload, 'payment.balance')
            ?? Arr::get($payload, 'balance')
            ?? Arr::get($payload, 'balance_usdt')
            ?? null;

        return new SupplierOrderResult(
            items: $items,
            externalOrderId: $extOrderId !== null && is_scalar($extOrderId) ? (string) $extOrderId : null,
            total: $totalCost !== null && is_numeric($totalCost) ? (float) $totalCost : null,
            balanceAfter: $balAfter !== null && is_numeric($balAfter) ? (float) $balAfter : null,
            raw: $payload,
        );
    }

    protected function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new SupplierException('Could not connect to supplier: '.$e->getMessage(), previous: $e);
        }
    }

    /** Maps HTTP / payload errors to typed exceptions. */
    protected function guard(Response $response): void
    {
        $failed = $response->failed() || $response->json('ok') === false || $response->json('success') === false;
        if (! $failed) {
            return;
        }

        $rawErr = $response->json('error.message')
            ?? $response->json('error')
            ?? $response->json('message')
            ?? $response->json('detail');

        $message = is_scalar($rawErr)
            ? (string) $rawErr
            : (is_array($rawErr) ? (string) json_encode($rawErr) : '');
        $lower = Str::lower($message);

        if (in_array($response->status(), [401, 403], true)) {
            throw SupplierAuthException::withContext($message ?: 'Invalid API key.');
        }

        if ($response->status() === 402 || Str::contains($lower, ['insufficient', 'balance', 'not enough', 'solde insuffisant'])) {
            throw SupplierInsufficientBalanceException::withContext($message ?: 'Insufficient balance.');
        }

        if ($response->status() === 409 || Str::contains($lower, ['out of stock', 'no stock', 'unavailable', 'sold out', 'inventory not enough'])) {
            throw SupplierOutOfStockException::withContext($message ?: 'Out of stock.');
        }

        throw SupplierException::withContext(
            $message ?: 'Supplier request failed with HTTP '.$response->status(),
            ['status' => $response->status(), 'body' => Str::limit($response->body(), 500)]
        );
    }
}
