<?php

namespace App\Services\Suppliers\Contracts;

use App\Services\Suppliers\Data\SupplierBalance;
use App\Services\Suppliers\Data\SupplierOrderResult;
use App\Services\Suppliers\Data\SupplierProductData;

/**
 * Every wholesale source (Telegram shop bot API, reseller panel, ...) implements this.
 * Add a new driver class + register it in SupplierManager to support a new API format.
 */
interface SupplierDriver
{
    /** @return SupplierProductData[] */
    public function products(): array;

    public function balance(): SupplierBalance;

    /**
     * Places an order. Must throw:
     *  - SupplierOutOfStockException      when the item is unavailable (nothing charged)
     *  - SupplierInsufficientBalanceException when wallet is too low (nothing charged)
     *  - SupplierAuthException            when the API key is rejected
     *  - SupplierUnknownResultException   when the request may or may not have been processed
     *  - SupplierException                for any other definitive failure
     */
    public function placeOrder(string $externalProductId, int $quantity, string $requestId): SupplierOrderResult;
}
