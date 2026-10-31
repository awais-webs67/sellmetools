<?php

namespace App\Services\Suppliers\Data;

final readonly class SupplierProductData
{
    public function __construct(
        public string $externalId,
        public string $name,
        public float $cost,
        public ?int $stock,
        public bool $unlimitedStock,
        public bool $available,
        public ?string $warranty = null,
        public ?string $description = null,
        public array $raw = [],
    ) {}
}
