<?php

namespace App\Enums;

enum SupplierOrderStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Unknown = 'unknown'; // request sent but result unclear (timeout), never auto-retry elsewhere

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Success => 'success',
            self::Failed => 'danger',
            self::Unknown => 'warning',
        };
    }
}
