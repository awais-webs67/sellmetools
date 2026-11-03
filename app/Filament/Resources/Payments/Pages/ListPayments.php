<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    public function getTabs(): array
    {
        \App\Services\PaymentService::syncOrphanedPendingPayments();

        $fetchCounts = fn (): array => Payment::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $counts = app()->runningUnitTests()
            ? $fetchCounts()
            : \Illuminate\Support\Facades\Cache::remember('smt_admin_tab_counts_payments', 5, $fetchCounts);

        $pending = (int) ($counts[PaymentStatus::Pending->value] ?? 0);
        $approved = (int) ($counts[PaymentStatus::Approved->value] ?? 0);
        $rejected = (int) ($counts[PaymentStatus::Rejected->value] ?? 0);
        $total = $pending + $approved + $rejected;

        return [
            'all' => Tab::make('All Payments')
                ->badge($total > 0 ? (string) $total : null)
                ->badgeColor('gray'),
            'pending' => Tab::make('Pending Review')
                ->badge($pending > 0 ? (string) $pending : null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $q): Builder => $q->where('status', PaymentStatus::Pending->value)),
            'approved' => Tab::make('Approved')
                ->badge($approved > 0 ? (string) $approved : null)
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $q): Builder => $q->where('status', PaymentStatus::Approved->value)),
            'rejected' => Tab::make('Rejected')
                ->badge($rejected > 0 ? (string) $rejected : null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $q): Builder => $q->where('status', PaymentStatus::Rejected->value)),
        ];
    }
}
