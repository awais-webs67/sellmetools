<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Suppliers\Data\SupplierBalance;
use App\Services\Suppliers\Data\SupplierProductData;
use App\Services\Suppliers\SupplierManager;
use App\Services\TelegramNotifier as T;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class SupplierSyncService
{
    public function __construct(
        protected SupplierManager $manager,
        protected PriceCalculator $pricing,
        protected InventoryService $inventory,
        protected T $telegram,
    ) {}

    /**
     * Pulls products + balance from a supplier, upserts them, then re-prices and
     * re-stocks every listing linked to this supplier.
     *
     * @return array{products: int, updated_listings: int, disabled: int, enabled: int}
     */
    public function sync(Supplier $supplier): array
    {
        try {
            app(OrderService::class)->expireStale();
        } catch (Throwable) {
            // Ignore
        }

        $driver = $this->manager->driver($supplier);
        $balanceResult = null;

        try {
            if (method_exists($driver, 'productsAndBalance')) {
                $both = $driver->productsAndBalance();
                $rows = $both['products'];
                $balanceResult = $both['balance'] ?? null;
            } else {
                $rows = $driver->products();
            }
        } catch (Throwable $e) {
            $isConnectTimeout = str_contains(strtolower($e->getMessage()), 'could not connect')
                || str_contains(strtolower($e->getMessage()), 'connection timed out');
            if (! $isConnectTimeout) {
                $this->syncBalance($supplier);
            }
            $supplier->forceFill(['last_sync_error' => $e->getMessage()])->save();
            throw $e;
        }

        return $this->applySyncedRows($supplier, $rows, $balanceResult);
    }

    /**
     * Ingests raw JSON payloads fetched by the Admin Browser Bridge (for CORS-enabled Railway suppliers).
     *
     * @return array{products: int, updated_listings: int, disabled: int, enabled: int}
     */
    public function ingestExternalPayload(Supplier $supplier, array $productsPayload, ?array $balancePayload = null): array
    {
        $driver = $this->manager->driver($supplier);

        Cache::put('supplier_raw_cache_'.$supplier->id, [
            'products' => $productsPayload,
            'balance' => $balancePayload ?? $productsPayload,
            'at' => now()->timestamp,
        ], 86400);
        Cache::forget('supplier_needs_bridge_sync_'.$supplier->id);

        $rows = method_exists($driver, 'parseProductsPayloadArray')
            ? $driver->parseProductsPayloadArray($productsPayload)
            : [];

        $balanceResult = null;
        if (method_exists($driver, 'extractBalanceFromPayloadArray')) {
            if (is_array($balancePayload)) {
                $balanceResult = $driver->extractBalanceFromPayloadArray($balancePayload);
            }
            if ($balanceResult === null) {
                $balanceResult = $driver->extractBalanceFromPayloadArray($productsPayload);
            }
        }

        return $this->applySyncedRows($supplier, $rows, $balanceResult);
    }

    /**
     * @param  SupplierProductData[]  $rows
     * @return array{products: int, updated_listings: int, disabled: int, enabled: int}
     */
    protected function applySyncedRows(Supplier $supplier, array $rows, ?SupplierBalance $balanceResult): array
    {
        $now = now();
        $seen = [];
        $existingByExtId = $supplier->products()->get()->keyBy(fn (SupplierProduct $sp) => (string) $sp->external_id);
        $changedIds = [];

        DB::transaction(function () use ($supplier, $rows, $now, &$seen, $existingByExtId, &$changedIds) {
            foreach ($rows as $row) {
                $extId = (string) $row->externalId;
                $seen[] = $extId;

                /** @var SupplierProduct|null $existing */
                $existing = $existingByExtId->get($extId);

                if ($existing) {
                    $same = (string) $existing->name === (string) $row->name
                        && abs((float) $existing->cost - (float) $row->cost) < 0.0001
                        && $existing->stock === $row->stock
                        && (bool) $existing->unlimited_stock === (bool) $row->unlimitedStock
                        && (bool) $existing->is_available === (bool) $row->available
                        && (string) ($existing->warranty ?? '') === (string) ($row->warranty ?? '')
                        && (string) ($existing->description ?? '') === (string) ($row->description ?? '');

                    if ($same) {
                        continue;
                    }

                    $existing->update([
                        'name' => $row->name,
                        'cost' => $row->cost,
                        'stock' => $row->stock,
                        'unlimited_stock' => $row->unlimitedStock,
                        'is_available' => $row->available,
                        'warranty' => $row->warranty,
                        'description' => $row->description,
                        'raw' => $row->raw,
                        'last_seen_at' => $now,
                    ]);
                    $changedIds[] = $existing->id;
                } else {
                    $created = SupplierProduct::create([
                        'supplier_id' => $supplier->id,
                        'external_id' => $extId,
                        'name' => $row->name,
                        'cost' => $row->cost,
                        'stock' => $row->stock,
                        'unlimited_stock' => $row->unlimitedStock,
                        'is_available' => $row->available,
                        'warranty' => $row->warranty,
                        'description' => $row->description,
                        'raw' => $row->raw,
                        'last_seen_at' => $now,
                    ]);
                    $changedIds[] = $created->id;
                }
            }

            // Products removed by the supplier become unavailable (kept for history).
            $disappearedIds = $existingByExtId
                ->filter(fn (SupplierProduct $sp) => ! in_array((string) $sp->external_id, $seen, true) && ($sp->is_available || (int) $sp->stock !== 0))
                ->pluck('id')
                ->all();

            if ($disappearedIds !== []) {
                SupplierProduct::whereIn('id', $disappearedIds)->update(['is_available' => false, 'stock' => 0]);
                $changedIds = array_merge($changedIds, $disappearedIds);
            }
        });

        if ($balanceResult !== null) {
            $this->recordBalance($supplier, $balanceResult->balance);
        } else {
            $this->syncBalance($supplier);
        }

        $supplier->forceFill(['last_synced_at' => $now, 'last_sync_error' => null])->save();

        // Always reconcile all listings linked to this supplier so any drifted product stock/price self-heals on every sync
        return ['products' => count($rows)] + $this->refreshListings($supplier, null);
    }

    public function syncBalance(Supplier $supplier): void
    {
        try {
            $balance = $this->manager->driver($supplier)->balance();
            $this->recordBalance($supplier, $balance->balance);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Stores balance and alerts once when it drops below the threshold. */
    public function recordBalance(Supplier $supplier, float $balance): void
    {
        $supplier->balance = $balance;

        $low = $balance < (float) $supplier->low_balance_threshold;

        if ($low && ! $supplier->balance_alerted_at) {
            $supplier->balance_alerted_at = now();
            $this->telegram->notifyAdmin(
                "⚠️ <b>Low supplier balance</b>\n".
                'Supplier: '.T::e($supplier->name)."\n".
                "Balance: <b>{$balance} {$supplier->currency}</b>\n".
                'Please top up to keep auto-delivery running.'
            );
            app(WhatsAppNotifier::class)->notifyAdminLowSupplierBalance(
                (string) $supplier->name,
                $balance,
                (string) $supplier->currency
            );
        } elseif (! $low) {
            $supplier->balance_alerted_at = null;
        }

        $supplier->save();
    }

    /**
     * @param  int[]|null  $onlySupplierProductIds  When an array is passed, only refreshes listings linked to those changed SupplierProduct IDs.
     * @return array{updated_listings: int, disabled: int, enabled: int}
     */
    public function refreshListings(Supplier $supplier, ?array $onlySupplierProductIds = null): array
    {
        $stats = ['updated_listings' => 0, 'disabled' => 0, 'enabled' => 0];

        if ($onlySupplierProductIds !== null && $onlySupplierProductIds === []) {
            return $stats;
        }

        $products = Product::query()
            ->with('activeSources.supplier')
            ->whereHas('sources', function ($q) use ($supplier, $onlySupplierProductIds) {
                $q->where('supplier_id', $supplier->id);
                if ($onlySupplierProductIds !== null) {
                    $q->whereIn('supplier_products.id', $onlySupplierProductIds);
                }
            })
            ->get();

        foreach ($products as $product) {
            $result = $this->pricing->refresh($product);
            $this->inventory->refresh($product);

            $stats['updated_listings'] += (int) $result['changed'];

            if ($result['disabled']) {
                $stats['disabled']++;
                $this->telegram->notifyAdmin(
                    "🛑 <b>Product auto-disabled</b>\n".
                    T::e($product->name)."\n".
                    "Supplier cost Rs {$product->cost_price} &gt; your price Rs {$product->price}.\n".
                    'Update the price in admin to re-enable.'
                );
            }

            if ($result['enabled']) {
                $stats['enabled']++;
                $this->telegram->notifyAdmin("✅ <b>Product re-enabled</b>\n".T::e($product->name).', supplier price is profitable again.');
            }
        }

        if ($stats['updated_listings'] > 0 || $stats['disabled'] > 0 || $stats['enabled'] > 0) {
            Cache::forget('smt_search_catalog_v1');
        }

        return $stats;
    }
}
