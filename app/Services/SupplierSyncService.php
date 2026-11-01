<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Suppliers\SupplierManager;
use App\Services\TelegramNotifier as T;
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
            // Even if /products failed, still try to update balance so balance stays fresh
            $this->syncBalance($supplier);
            $supplier->forceFill(['last_sync_error' => $e->getMessage()])->save();
            throw $e;
        }

        $now = now();
        $seen = [];

        DB::transaction(function () use ($supplier, $rows, $now, &$seen) {
            foreach ($rows as $row) {
                $seen[] = $row->externalId;

                SupplierProduct::updateOrCreate(
                    ['supplier_id' => $supplier->id, 'external_id' => $row->externalId],
                    [
                        'name' => $row->name,
                        'cost' => $row->cost,
                        'stock' => $row->stock,
                        'unlimited_stock' => $row->unlimitedStock,
                        'is_available' => $row->available,
                        'warranty' => $row->warranty,
                        'description' => $row->description,
                        'raw' => $row->raw,
                        'last_seen_at' => $now,
                    ]
                );
            }

            // Products removed by the supplier become unavailable (kept for history).
            $supplier->products()
                ->whereNotIn('external_id', $seen)
                ->update(['is_available' => false, 'stock' => 0]);
        });

        if ($balanceResult !== null) {
            $this->recordBalance($supplier, $balanceResult->balance);
        } else {
            $this->syncBalance($supplier);
        }

        $supplier->forceFill(['last_synced_at' => $now, 'last_sync_error' => null])->save();

        return ['products' => count($rows)] + $this->refreshListings($supplier);
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
        } elseif (! $low) {
            $supplier->balance_alerted_at = null;
        }

        $supplier->save();
    }

    /** @return array{updated_listings: int, disabled: int, enabled: int} */
    public function refreshListings(Supplier $supplier): array
    {
        $products = Product::query()
            ->whereHas('sources', fn ($q) => $q->where('supplier_id', $supplier->id))
            ->get();

        $stats = ['updated_listings' => 0, 'disabled' => 0, 'enabled' => 0];

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
                $this->telegram->notifyAdmin("✅ <b>Product re-enabled</b>\n".T::e($product->name).' – supplier price is profitable again.');
            }
        }

        return $stats;
    }
}
