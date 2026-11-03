<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\Actions\PaymentActions;
use App\Filament\Support\Money;
use App\Models\Payment;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order.items.product.activeSources.supplier', 'order.items.product.sources.supplier', 'paymentMethod', 'reviewer']))
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('order.number')
                    ->label('Order #')
                    ->searchable()
                    ->weight('bold')
                    ->color('primary')
                    ->url(fn (Payment $record): ?string => $record->order
                        ? OrderResource::getUrl('view', ['record' => $record->order])
                        : null)
                    ->description(fn (Payment $record): ?string => $record->order?->email),
                TextColumn::make('supplier_badge')
                    ->label('🏭 Supplier')
                    ->badge()
                    ->color('info')
                    ->state(fn (Payment $record): string => $record->order?->adminSupplierSummary(false) ?? '-'),
                TextColumn::make('paymentMethod.name')
                    ->label('Gateway')
                    ->placeholder('-'),
                TextColumn::make('amount')
                    ->weight('bold')
                    ->formatStateUsing(fn (Payment $record): string => Money::currency($record->amount, $record->currency))
                    ->description(fn (Payment $record): ?string => strtoupper($record->currency) !== 'PKR' && $record->order
                        ? 'Order total '.Money::pkr($record->order->total)
                        : null)
                    ->sortable(),
                TextColumn::make('transaction_id')
                    ->label('TID / Reference')
                    ->searchable()
                    ->copyable()
                    ->weight('bold')
                    ->fontFamily('mono'),
                TextColumn::make('sender_number')
                    ->label('Sender')
                    ->searchable()
                    ->copyable()
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state): string => ucfirst($state->value))
                    ->color(fn (PaymentStatus $state): string => $state->color())
                    ->description(fn (Payment $record): ?string => $record->reject_reason),
                TextColumn::make('reviewer.name')
                    ->label('Reviewed by')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(['default' => 1, 'sm' => 3])
            ->filters([
                SelectFilter::make('period')
                    ->label('Date Range')
                    ->options([
                        'today' => '📅 Today',
                        'yesterday' => '⏮️ Yesterday',
                        'week' => '📆 Last 7 Days (Weekly)',
                        'month' => '🗓️ Last 30 Days (Monthly)',
                        'all' => '🌐 All Time',
                    ])
                    ->default('today')
                    ->selectablePlaceholder(false)
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? 'all') {
                            'today' => $query->whereDate('created_at', today()),
                            'yesterday' => $query->whereDate('created_at', today()->subDay()),
                            'week' => $query->where('created_at', '>=', now()->subDays(7)->startOfDay()),
                            'month' => $query->where('created_at', '>=', now()->subDays(30)->startOfDay()),
                            default => $query,
                        };
                    }),
                SelectFilter::make('status')
                    ->options(collect(PaymentStatus::cases())->mapWithKeys(fn (PaymentStatus $s) => [$s->value => ucfirst($s->value)])->all()),
                SelectFilter::make('payment_method_id')
                    ->label('Method')
                    ->relationship('paymentMethod', 'name'),
            ])
            ->recordActions([
                PaymentActions::approve()->button()->size('xs'),
                PaymentActions::reject()->button()->size('xs'),
                PaymentActions::screenshot()->iconButton()->tooltip('View screenshot'),
                Action::make('order')
                    ->label('View Order')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->button()
                    ->size('xs')
                    ->url(fn (Payment $record): ?string => $record->order
                        ? OrderResource::getUrl('view', ['record' => $record->order])
                        : null),
            ]);
    }
}
