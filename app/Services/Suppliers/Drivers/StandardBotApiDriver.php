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

    protected function http(int $timeout = 20): PendingRequest
    {
        return Http::baseUrl(rtrim($this->supplier->base_url, '/'))
            ->withHeaders([
                'x-api-key' => $this->supplier->api_key,
                'Authorization' => 'Bearer '.$this->supplier->api_key,
            ])
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout(10);
    }

    public function products(): array
    {
        $response = $this->send(fn () => $this->http(30)->retry(2, 1000, throw: false)->get('/products'));
        $this->guard($response);

        $rows = $response->json('products');
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
        $response = $this->send(fn () => $this->http()->get('/balance'));
        $this->guard($response);

        return new SupplierBalance(
            balance: (float) $response->json('balance', 0),
            currency: (string) $response->json('currency', $this->supplier->currency),
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
        $items = is_array($order['items'] ?? null) ? array_values(array_map('strval', $order['items'])) : [];

        if ($items === []) {
            throw SupplierUnknownResultException::withContext('Order accepted but no items were returned.', ['body' => $response->json()]);
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
