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
        if (! $prodResponse instanceof Response) {
            throw new SupplierException('Could not connect to supplier /products endpoint.');
        }
        $this->guard($prodResponse);

        $products = $this->parseProductsResponse($prodResponse);

        $balanceObj = null;
        $balResponse = $responses['balance'] ?? null;
        if ($balResponse instanceof Response && $balResponse->successful() && $balResponse->json('ok') !== false) {
            $rawBal = $balResponse->json('balance')
                ?? $balResponse->json('data.balance')
                ?? $balResponse->json('user.balance')
                ?? $balResponse->json('wallet')
                ?? $balResponse->json('credits');

            if ($rawBal !== null && is_numeric($rawBal)) {
                $balanceObj = new SupplierBalance(
                    balance: (float) $rawBal,
                    currency: (string) ($balResponse->json('currency') ?? $balResponse->json('data.currency') ?? $this->supplier->currency),
                    raw: $balResponse->json() ?? [],
                );
            }
        } elseif (($inlineBal = $prodResponse->json('balance') ?? $prodResponse->json('user.balance')) !== null && is_numeric($inlineBal)) {
            $balanceObj = new SupplierBalance(
                balance: (float) $inlineBal,
                currency: (string) ($prodResponse->json('currency') ?? $this->supplier->currency),
                raw: $prodResponse->json() ?? [],
            );
        }

        return [
            'products' => $products,
            'balance' => $balanceObj,
        ];
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
        $rows = $response->json('products') ?? $response->json('data.products') ?? $response->json('items');
        if (! is_array($rows)) {
            throw new SupplierException('Unexpected products response format.');
        }

        return array_values(array_filter(array_map(function ($row) {
            if (! is_array($row) || ! isset($row['id'], $row['name'], $row['price'])) {
                return null;
            }

            $unlimited = (bool) ($row['unlimited_stock'] ?? false);
            $stock = isset($row['stock']) && is_numeric($row['stock']) ? (int) $row['stock'] : null;
            // A null stock without the unlimited flag is treated as unlimited only when marked available.
            if ($stock === null && ! $unlimited && ($row['available'] ?? false)) {
                $unlimited = true;
            }

            return new SupplierProductData(
                externalId: (string) $row['id'],
                name: TextCleaner::clean((string) $row['name']),
                cost: (float) $row['price'],
                stock: $stock,
                unlimitedStock: $unlimited,
                available: (bool) ($row['available'] ?? ($unlimited || $stock > 0)),
                warranty: TextCleaner::clean($row['warranty'] ?? null),
                description: TextCleaner::clean($row['description'] ?? null),
                raw: $row,
            );
        }, $rows)));
    }

    public function balance(): SupplierBalance
    {
        $response = $this->send(fn () => $this->http(8)->get('/balance'));
        $this->guard($response);

        $rawBal = $response->json('balance')
            ?? $response->json('data.balance')
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
        try {
            // No automatic retries: an order must never be sent twice by accident.
            $response = $this->http(60)->post('/order', [
                'product_id' => is_numeric($externalProductId) ? (int) $externalProductId : $externalProductId,
                'quantity' => $quantity,
                'request_id' => $requestId,
            ]);
        } catch (ConnectionException $e) {
            throw SupplierUnknownResultException::withContext('Connection error while placing order: '.$e->getMessage());
        }

        if ($response->serverError()) {
            throw SupplierUnknownResultException::withContext('Supplier server error '.$response->status(), ['body' => $response->body()]);
        }

        $this->guard($response);

        $order = $response->json('order');
        $rawItems = is_array($order['items'] ?? null)
            ? $order['items']
            : ($response->json('items') ?? $response->json('data.items') ?? []);

        $items = is_array($rawItems)
            ? array_values(array_filter(
                array_map(fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $rawItems),
                fn (string $v): bool => $v !== ''
            ))
            : [];

        if ($items === []) {
            throw SupplierUnknownResultException::withContext('Order accepted by supplier but returned empty credentials.', ['body' => $response->json()]);
        }

        return new SupplierOrderResult(
            items: $items,
            externalOrderId: isset($order['order_id']) ? (string) $order['order_id'] : null,
            total: isset($order['total']) ? (float) $order['total'] : null,
            balanceAfter: isset($order['balance_after']) ? (float) $order['balance_after'] : null,
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
        $failed = $response->failed() || $response->json('ok') === false;
        if (! $failed) {
            return;
        }

        $message = (string) ($response->json('error') ?? $response->json('message') ?? '');
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
