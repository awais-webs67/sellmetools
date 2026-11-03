<?php

namespace App\Services\Suppliers\Drivers;

use App\Models\Supplier;
use App\Services\Suppliers\Contracts\SupplierDriver;
use App\Services\Suppliers\Data\SupplierBalance;
use App\Services\Suppliers\Data\SupplierOrderResult;
use App\Services\Suppliers\Data\SupplierProductData;
use App\Services\Suppliers\Exceptions\SupplierAuthException;
use App\Services\Suppliers\Exceptions\SupplierException;
use App\Services\Suppliers\Exceptions\SupplierInsufficientBalanceException;
use App\Services\Suppliers\Exceptions\SupplierOutOfStockException;
use App\Services\Suppliers\Exceptions\SupplierUnknownResultException;
use App\Support\TextCleaner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
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
        return [
            'x-api-key' => $this->supplier->api_key,
            'x-buyer-key' => $this->supplier->api_key,
            'Authorization' => 'Bearer '.$this->supplier->api_key,
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

    protected function http(int $timeout = 12): PendingRequest
    {
        return Http::baseUrl($this->normalizedBaseUrl())
            ->withHeaders($this->defaultHeaders())
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout(5);
    }

    /**
     * Fetches /products and /balance in parallel using Http::pool for 2x faster live sync.
     *
     * @return array{products: SupplierProductData[], balance: ?SupplierBalance}
     */
    public function productsAndBalance(): array
    {
        $baseUrl = $this->normalizedBaseUrl();
        $headers = $this->defaultHeaders();
        $productsPath = $this->productsEndpoint();

        $responses = Http::pool(fn (Pool $pool) => [
            $pool->as('products')->baseUrl($baseUrl)->withHeaders($headers)->acceptJson()->connectTimeout(5)->timeout(10)->get($productsPath),
            $pool->as('balance')->baseUrl($baseUrl)->withHeaders($headers)->acceptJson()->connectTimeout(5)->timeout(8)->get('/balance'),
        ]);

        $prodResponse = $responses['products'] ?? null;
        $balResponse = $responses['balance'] ?? null;
        if (! $prodResponse instanceof Response) {
            throw new SupplierException('Could not connect to supplier /products endpoint.');
        }
        $this->guard($prodResponse);

        $products = $this->parseProductsResponse($prodResponse);

        $balanceObj = $this->extractBalanceFromResponse($balResponse)
            ?? $this->extractBalanceFromResponse($prodResponse);

        // Fallback for suppliers like BunaiStore where balance is on GET /me instead of /balance
        if ($balanceObj === null && $balResponse instanceof Response && $balResponse->status() === 404) {
            try {
                $meResponse = $this->http(6)->get('/me');
                $balanceObj = $this->extractBalanceFromResponse($meResponse);
            } catch (\Throwable) {
                // Ignore if /me is also unavailable
            }
        }

        return [
            'products' => $products,
            'balance' => $balanceObj,
        ];
    }

    protected function extractBalanceFromResponse(?Response $response): ?SupplierBalance
    {
        if (! $response instanceof Response || ! $response->successful() || $response->json('ok') === false || $response->json('success') === false) {
            return null;
        }

        $rawBal = $response->json('balance')
            ?? $response->json('balance_usdt')
            ?? $response->json('balanceUsd')
            ?? $response->json('usdtBalance')
            ?? $response->json('data.balance')
            ?? $response->json('data.balance_usdt')
            ?? $response->json('user.balance')
            ?? $response->json('wallet')
            ?? $response->json('credits');

        if ($rawBal === null || ! is_numeric($rawBal)) {
            return null;
        }

        return new SupplierBalance(
            balance: (float) $rawBal,
            currency: (string) ($response->json('currency') ?? $response->json('walletCurrency') ?? $response->json('data.currency') ?? $this->supplier->currency),
            raw: $response->json() ?? [],
        );
    }

    public function products(): array
    {
        $response = $this->send(fn () => $this->http(10)->get($this->productsEndpoint()));
        $this->guard($response);

        return $this->parseProductsResponse($response);
    }

    /**
     * @return SupplierProductData[]
     */
    protected function parseProductsResponse(Response $response): array
    {
        $rows = $response->json('products')
            ?? $response->json('data.products')
            ?? $response->json('items')
            ?? $response->json('data.items')
            ?? $response->json('variants')
            ?? (is_array($response->json()) && array_is_list($response->json()) ? $response->json() : null);

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
        $response = $this->send(fn () => $this->http(8)->get('/balance'));
        if ($response->status() === 404) {
            $response = $this->send(fn () => $this->http(8)->get('/me'));
        }
        $this->guard($response);

        $rawBal = $response->json('balance')
            ?? $response->json('balance_usdt')
            ?? $response->json('balanceUsd')
            ?? $response->json('usdtBalance')
            ?? $response->json('data.balance')
            ?? $response->json('data.balance_usdt')
            ?? $response->json('user.balance')
            ?? $response->json('wallet')
            ?? $response->json('credits')
            ?? 0;

        return new SupplierBalance(
            balance: (float) $rawBal,
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

        try {
            if ($isProdSeller) {
                $response = $this->http(60)
                    ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                    ->post('/orders', [
                        'productId' => (string) $externalProductId,
                        'quantity' => $quantity,
                    ]);
            } elseif ($isBunai) {
                $response = $this->http(60)
                    ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                    ->post('/orders', [
                        'product_id' => (string) $externalProductId,
                        'qty' => $quantity,
                        'include_after_note' => true,
                    ]);
            } elseif ($isQamify) {
                $response = $this->http(60)
                    ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                    ->post('/orders', [
                        'product_id' => $normalizedId,
                        'qty' => $quantity,
                    ]);
            } elseif ($isTunvn) {
                $response = $this->http(60)->post('/buy', [
                    'product_id' => $normalizedId,
                    'quantity' => $quantity,
                    'currency' => strtolower($this->supplier->currency ?: 'usdt'),
                ]);
            } elseif ($isCanboso) {
                $response = $this->http(60)
                    ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                    ->post('/purchase', [
                        'key' => $apiKey,
                        'product_id' => (string) $externalProductId,
                        'quantity' => $quantity,
                    ]);
            } else {
                // Default / Standard Bot / EliteToolz
                $response = $this->http(60)->post('/order', [
                    'product_id' => $normalizedId,
                    'productId' => (string) $externalProductId,
                    'quantity' => $quantity,
                    'request_id' => $requestId,
                ]);

                // Safe 404 fallback (route does not exist -> nothing was charged)
                if ($response->status() === 404) {
                    $response = $this->http(60)
                        ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                        ->post('/orders', [
                            'product_id' => $normalizedId,
                            'productId' => (string) $externalProductId,
                            'qty' => $quantity,
                            'quantity' => $quantity,
                        ]);
                }
            }
        } catch (ConnectionException $e) {
            throw SupplierUnknownResultException::withContext('Connection error while placing order: '.$e->getMessage());
        }

        if ($response->serverError()) {
            throw SupplierUnknownResultException::withContext('Supplier server error '.$response->status(), ['body' => $response->body()]);
        }

        $this->guard($response);

        $order = is_array($response->json('order')) ? $response->json('order') : (is_array($response->json('data')) ? $response->json('data') : []);
        $rawItems = $order['items']
            ?? $order['deliveredKeys']
            ?? $order['deliveredKey']
            ?? $order['accounts']
            ?? $order['codes']
            ?? $order['deliveries']
            ?? $response->json('deliveredKeys')
            ?? $response->json('deliveredKey')
            ?? $response->json('items')
            ?? $response->json('accounts')
            ?? $response->json('codes')
            ?? $response->json('delivery.accounts')
            ?? $response->json('data.items')
            ?? $response->json('data.accounts')
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
            throw SupplierUnknownResultException::withContext('Order accepted by supplier but returned empty credentials.', ['body' => $response->json()]);
        }

        $extOrderId = $order['order_id']
            ?? $order['orderId']
            ?? $order['orderCode']
            ?? $order['code']
            ?? $order['id']
            ?? $response->json('orderId')
            ?? $response->json('order_id')
            ?? $response->json('order.orderCode')
            ?? $response->json('code')
            ?? $response->json('id')
            ?? null;

        $totalCost = $order['total']
            ?? $order['total_paid']
            ?? $order['total_usdt']
            ?? $order['amount']
            ?? $order['price']
            ?? $response->json('amount')
            ?? $response->json('total')
            ?? $response->json('total_paid')
            ?? $response->json('total_usdt')
            ?? $response->json('payment.amount')
            ?? $response->json('price')
            ?? null;

        $balAfter = $order['balance_after']
            ?? $response->json('balance_after')
            ?? $response->json('payment.balance')
            ?? $response->json('balance')
            ?? $response->json('balance_usdt')
            ?? null;

        return new SupplierOrderResult(
            items: $items,
            externalOrderId: $extOrderId !== null && is_scalar($extOrderId) ? (string) $extOrderId : null,
            total: $totalCost !== null && is_numeric($totalCost) ? (float) $totalCost : null,
            balanceAfter: $balAfter !== null && is_numeric($balAfter) ? (float) $balAfter : null,
            raw: $response->json() ?? [],
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
