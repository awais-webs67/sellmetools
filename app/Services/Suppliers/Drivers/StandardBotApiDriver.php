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
 * Driver for the common Telegram "shop bot" reseller API:
 *   GET  /products  -> { ok, products: [{ id, name, price, stock, available, unlimited_stock, warranty, description }] }
 *   GET  /balance   -> { ok, balance, currency }
 *   POST /order     -> { ok, order: { order_id, items: [...], total, balance_after } }
 */
class StandardBotApiDriver implements SupplierDriver
{
    public function __construct(protected Supplier $supplier) {}

    protected function http(int $timeout = 12): PendingRequest
    {
        return Http::baseUrl(rtrim($this->supplier->base_url, '/'))
            ->withHeaders([
                'x-api-key' => $this->supplier->api_key,
                'Authorization' => 'Bearer '.$this->supplier->api_key,
            ])
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
        $baseUrl = rtrim($this->supplier->base_url, '/');
        $headers = [
            'x-api-key' => $this->supplier->api_key,
            'Authorization' => 'Bearer '.$this->supplier->api_key,
        ];

        $responses = Http::pool(fn (Pool $pool) => [
            $pool->as('products')->baseUrl($baseUrl)->withHeaders($headers)->acceptJson()->connectTimeout(5)->timeout(10)->get('/products'),
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
            currency: (string) ($response->json('currency') ?? $response->json('data.currency') ?? $this->supplier->currency),
            raw: $response->json() ?? [],
        );
    }

    public function products(): array
    {
        $response = $this->send(fn () => $this->http(10)->get('/products'));
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
            $name = $row['name'] ?? $row['title'] ?? $row['product_name'] ?? null;
            $rawPrice = $row['price'] ?? $row['unit_price'] ?? $row['price_usdt'] ?? $row['cost'] ?? null;

            if ($id === null || $name === null || $rawPrice === null || ! is_numeric($rawPrice)) {
                return null;
            }

            $unlimited = (bool) ($row['unlimited_stock'] ?? $row['stock_unlimited'] ?? false);
            $rawStock = $row['stock'] ?? $row['quantity'] ?? $row['available_stock'] ?? null;
            $stock = $rawStock !== null && is_numeric($rawStock) ? (int) $rawStock : null;

            // A null stock without the unlimited flag is treated as unlimited only when marked available.
            if ($stock === null && ! $unlimited && ($row['available'] ?? $row['is_available'] ?? false)) {
                $unlimited = true;
            }

            $warranty = $row['warranty'] ?? $row['warranty_period'] ?? $row['subscription_type'] ?? null;
            $description = $row['description'] ?? $row['instructions'] ?? null;

            return new SupplierProductData(
                externalId: (string) $id,
                name: TextCleaner::clean((string) $name),
                cost: (float) $rawPrice,
                stock: $stock,
                unlimitedStock: $unlimited,
                available: (bool) ($row['available'] ?? $row['is_available'] ?? ($unlimited || $stock > 0)),
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
            ?? $response->json('data.balance')
            ?? $response->json('data.balance_usdt')
            ?? $response->json('user.balance')
            ?? $response->json('wallet')
            ?? $response->json('credits')
            ?? 0;

        return new SupplierBalance(
            balance: (float) $rawBal,
            currency: (string) ($response->json('currency') ?? $response->json('data.currency') ?? $this->supplier->currency),
            raw: $response->json() ?? [],
        );
    }

    public function placeOrder(string $externalProductId, int $quantity, string $requestId): SupplierOrderResult
    {
        $baseUrl = strtolower((string) $this->supplier->base_url);
        $apiKey = (string) $this->supplier->api_key;
        $normalizedId = is_numeric($externalProductId) ? (int) $externalProductId : $externalProductId;

        $isQamifyOrBunai = str_contains($baseUrl, 'qamify')
            || str_contains($baseUrl, 'bunaistore')
            || str_starts_with($apiKey, 'qamify_')
            || str_starts_with($apiKey, 'psk_');
        $isTunvn = str_contains($baseUrl, 'tunvnmmo');
        $isCanboso = str_contains($baseUrl, 'canboso') || str_starts_with($apiKey, 'tgb_');

        try {
            if ($isQamifyOrBunai) {
                $response = $this->http(60)
                    ->withHeaders(['Idempotency-Key' => $requestId])
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
                $response = $this->http(60)->post('/purchase', [
                    'product_id' => $normalizedId,
                    'productId' => (string) $externalProductId,
                    'quantity' => $quantity,
                    'qty' => $quantity,
                    'request_id' => $requestId,
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
                        ->withHeaders(['Idempotency-Key' => $requestId])
                        ->post('/orders', [
                            'product_id' => $normalizedId,
                            'qty' => $quantity,
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
            ?? $order['accounts']
            ?? $order['codes']
            ?? $order['deliveries']
            ?? $response->json('items')
            ?? $response->json('accounts')
            ?? $response->json('codes')
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
                        foreach (['content', 'account', 'credential', 'code', 'data', 'value', 'text'] as $k) {
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

        $extOrderId = $order['order_id'] ?? $order['code'] ?? $order['id'] ?? $response->json('order_id') ?? $response->json('code') ?? null;
        $totalCost = $order['total'] ?? $order['total_usdt'] ?? $response->json('total') ?? $response->json('total_usdt') ?? null;
        $balAfter = $order['balance_after'] ?? $response->json('balance_after') ?? $response->json('balance') ?? $response->json('balance_usdt') ?? null;

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

        if ($response->status() === 402 || Str::contains($lower, ['insufficient', 'balance', 'not enough'])) {
            throw SupplierInsufficientBalanceException::withContext($message ?: 'Insufficient balance.');
        }

        if ($response->status() === 409 || Str::contains($lower, ['out of stock', 'no stock', 'unavailable', 'sold out'])) {
            throw SupplierOutOfStockException::withContext($message ?: 'Out of stock.');
        }

        throw SupplierException::withContext(
            $message ?: 'Supplier request failed with HTTP '.$response->status(),
            ['status' => $response->status(), 'body' => Str::limit($response->body(), 500)]
        );
    }
}
