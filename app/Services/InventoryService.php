<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\StockStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\SupplierProduct;

class InventoryService
{
    /**
     * Live sellable quantity of a product.
     *
     * own available stock + supplier stock (all active sources).
     *
     * @return array{qty: int, unlimited: bool, own: int, supplier: int|null}
     */
    public function calculate(Product $product): array
    {
        // Restore any StockItems left in Reserved state on unpaid/expired/cancelled orders
        StockItem::query()
            ->where('product_id', $product->id)
            ->where('status', StockStatus::Reserved)
            ->where(function ($q) {
                $q->whereNull('order_item_id')
                    ->orWhereHas('orderItem.order', fn ($oq) => $oq->whereIn('status', [
                        OrderStatus::PendingPayment,
                        OrderStatus::Expired,
                        OrderStatus::Cancelled,
                        OrderStatus::Refunded,
                    ]));
            })
            ->update(['status' => StockStatus::Available, 'order_item_id' => null]);

        $own = $product->stockItems()->where('status', StockStatus::Available)->count();

        $product->loadMissing('activeSources.supplier');
        $supplierUnits = 0;
        $isNonConsumingMode = in_array($product->delivery_type, ['manual_admin', 'whatsapp_handover', 'instant_link'], true);
        $hasCustomLimit = $isNonConsumingMode && $product->stock_limit !== null;
        $unlimited = $isNonConsumingMode && ! $hasCustomLimit;

        if ($hasCustomLimit) {
            $supplierUnits = max(0, (int) $product->stock_limit);
        }

        foreach ($product->activeSources as $source) {
            /** @var SupplierProduct $source */
            $units = $source->availableUnits();
            if ($units === null) {
                if (! $hasCustomLimit) {
                    $unlimited = true;
                }
            } else {
                $supplierUnits += $units;
            }
        }

        $supplierFree = $unlimited ? null : max(0, $supplierUnits);

        return [
            'qty' => ($isNonConsumingMode ? 0 : $own) + ($supplierFree ?? 0),
            'unlimited' => $unlimited,
            'own' => $own,
            'supplier' => $supplierFree,
        ];
    }

    /** Supplier units still owed to open orders (decremented as fulfillment delivers them). */
    public function reservedSupplierQty(Product $product): int
    {
        return (int) OrderItem::query()
            ->where('product_id', $product->id)
            ->whereHas('order', fn ($q) => $q->whereIn('status', OrderStatus::holdingStock()))
            ->sum('reserved_supplier_qty');
    }

    /** Updates the denormalised stock snapshot used by the storefront / live polling. */
    public function refresh(Product $product): void
    {
        $wasInStock = $product->inStock();
        $stock = $this->calculate($product);

        $product->fill([
            'available_stock' => $stock['qty'],
            'stock_unlimited' => $stock['unlimited'],
        ]);

        if ($product->isDirty(['available_stock', 'stock_unlimited'])) {
            $product->saveQuietly();
        }

        $nowInStock = $product->inStock();
        $alertKey = "out_of_stock_alerted:{$product->id}";

        if ($nowInStock) {
            \Illuminate\Support\Facades\Cache::forget($alertKey);
        } elseif ($wasInStock && ! $nowInStock && $product->is_active && ! app()->runningUnitTests()) {
            if (! \Illuminate\Support\Facades\Cache::has($alertKey)) {
                \Illuminate\Support\Facades\Cache::put($alertKey, true, now()->addDays(7));
                $this->dispatchOutOfStockAlert($product);
            }
        }
    }

    protected function dispatchOutOfStockAlert(Product $product): void
    {
        $sendAlert = function () use ($product): void {
            try {
                $supplierSummary = $product->adminSupplierSummary(true);
                $priceText = money($product->price);
                $adminProductsUrl = url('/admin/products');

                app(TelegramNotifier::class)->notifyAdmin(
                    "🚨📦 <b>OUT OF STOCK ALERT!</b>\n".
                    "━━━━━━━━━━━━━━━━━━\n".
                    '📦 <b>Product:</b> '.TelegramNotifier::e($product->name)."\n".
                    '🏭 <b>Supplier / Source:</b> '.TelegramNotifier::e($supplierSummary)."\n".
                    "📉 <b>Current Stock:</b> <b>0 (Sold Out)</b>\n".
                    '💰 <b>Price:</b> '.TelegramNotifier::e($priceText)."\n".
                    "━━━━━━━━━━━━━━━━━━\n".
                    "⚠️ Please restock .TXT vault or check supplier stock in <a href=\"{$adminProductsUrl}\">Admin Panel</a>."
                );

                app(WhatsAppNotifier::class)->notifyAdminOutOfStock($product, $supplierSummary);
            } catch (\Throwable $e) {
                report($e);
            }
        };

        defer($sendAlert);
    }

    public function refreshMany(iterable $products): void
    {
        foreach ($products as $product) {
            $this->refresh($product);
        }
    }
}
