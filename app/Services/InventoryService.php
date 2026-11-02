<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\StockStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SupplierProduct;

class InventoryService
{
    /**
     * Live sellable quantity of a product.
     *
     * own available stock + supplier stock (all active sources) − supplier units already
     * reserved by unpaid / in-progress orders.
     *
     * @return array{qty: int, unlimited: bool, own: int, supplier: int|null}
     */
    public function calculate(Product $product): array
    {
        $own = $product->stockItems()->where('status', StockStatus::Available)->count();

        $product->load('activeSources.supplier');
        $supplierUnits = 0;
        $unlimited = in_array($product->delivery_type, ['manual_admin', 'whatsapp_handover', 'instant_link'], true);

        foreach ($product->activeSources as $source) {
            /** @var SupplierProduct $source */
            $units = $source->availableUnits();
            if ($units === null) {
                $unlimited = true;
            } else {
                $supplierUnits += $units;
            }
        }

        $reserved = $this->reservedSupplierQty($product);
        $supplierFree = $unlimited ? null : max(0, $supplierUnits - $reserved);

        return [
            'qty' => $own + ($supplierFree ?? 0),
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
        $stock = $this->calculate($product);

        $product->forceFill([
            'available_stock' => $stock['qty'],
            'stock_unlimited' => $stock['unlimited'],
        ])->saveQuietly();
    }

    public function refreshMany(iterable $products): void
    {
        foreach ($products as $product) {
            $this->refresh($product);
        }
    }
}
