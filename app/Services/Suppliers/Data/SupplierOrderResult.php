<?php

namespace App\Services\Suppliers\Data;

final readonly class SupplierOrderResult
{
    /**
     * @param  string[]  $items  Delivered item lines (credentials, links, codes...)
     */
    public function __construct(
        public array $items,
        public ?string $externalOrderId = null,
        public ?float $total = null,
        public ?float $balanceAfter = null,
        public array $raw = [],
    ) {}
}
