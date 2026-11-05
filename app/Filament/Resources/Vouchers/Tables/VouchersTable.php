<?php

namespace App\Filament\Resources\Vouchers\Tables;

use App\Enums\OrderStatus;
use App\Filament\Support\Money;
use App\Models\Product;
use App\Models\Voucher;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VouchersTable
{
    public static function configure(Table $table): Table
    {
        Voucher::ensureSchemaExists();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount([
                    'orders as total_orders_count' => fn (Builder $q) => $q->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Expired]),
                ])
                ->withSum([
                    'orders as total_discount_given' => fn (Builder $q) => $q->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Expired]),
                ], 'discount_amount')
                ->withSum([
                    'orders as total_revenue_generated' => fn (Builder $q) => $q->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Expired]),
                ], 'total'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label('Voucher Code')
                    ->searchable(['code', 'title'])
                    ->copyable()
                    ->copyMessage('Voucher code copied!')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->color('primary')
                    ->description(fn (Voucher $record): ?string => $record->title ?: null),

                TextColumn::make('discount_value')
                    ->label('Discount')
                    ->badge()
                    ->color('success')
                    ->state(fn (Voucher $record): string => $record->formattedDiscount())
                    ->description(function (Voucher $record): ?string {
                        $parts = [];
                        if ($record->min_order_amount !== null && (float) $record->min_order_amount > 0) {
                            $parts[] = 'Min: '.Money::pkr($record->min_order_amount);
                        }
                        if ($record->discount_type === 'percentage' && $record->max_discount_amount !== null && (float) $record->max_discount_amount > 0) {
                            $parts[] = 'Cap: '.Money::pkr($record->max_discount_amount);
                        }

                        return $parts !== [] ? implode(' · ', $parts) : null;
                    }),

                TextColumn::make('scope')
                    ->label('Applies To (Scope)')
                    ->badge()
                    ->color(fn (Voucher $record): string => $record->scope === 'all' ? 'info' : 'warning')
                    ->state(function (Voucher $record): string {
                        if ($record->scope === 'all') {
                            return 'All Products (Allover)';
                        }

                        $ids = array_values(array_filter(array_map('intval', (array) ($record->product_ids ?? []))));
                        if ($ids === []) {
                            return 'No Products Selected';
                        }

                        $names = Product::query()->whereIn('id', $ids)->pluck('name')->all();
                        if (count($names) <= 2) {
                            return implode(', ', $names);
                        }

                        return $names[0].' +'.(count($names) - 1).' more';
                    })
                    ->tooltip(function (Voucher $record): ?string {
                        if ($record->scope === 'all') {
                            return 'Valid on every product across the store';
                        }
                        $ids = array_values(array_filter(array_map('intval', (array) ($record->product_ids ?? []))));

                        return $ids !== [] ? Product::query()->whereIn('id', $ids)->pluck('name')->implode(', ') : null;
                    }),

                TextColumn::make('used_count')
                    ->label('Usage & Tracking')
                    ->weight('bold')
                    ->state(function (Voucher $record): string {
                        $used = (int) $record->used_count;
                        $max = $record->max_uses !== null ? (string) $record->max_uses : '∞';

                        return "{$used} / {$max} used";
                    })
                    ->description(function (Voucher $record): string {
                        $discountGiven = (float) ($record->total_discount_given ?? 0);
                        $revenue = (float) ($record->total_revenue_generated ?? 0);
                        $perCustomer = $record->max_uses_per_customer ? " · {$record->max_uses_per_customer}/cust" : '';

                        if ($discountGiven > 0 || $revenue > 0) {
                            return 'Saved '.Money::pkr($discountGiven).' · Sales '.Money::pkr($revenue).$perCustomer;
                        }

                        return 'No redemptions yet'.$perCustomer;
                    })
                    ->sortable(),

                TextColumn::make('status_badge')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Voucher $record): string => $record->statusLabel())
                    ->color(fn (Voucher $record): string => $record->statusColor())
                    ->description(fn (Voucher $record): ?string => $record->expires_at
                        ? 'Exp: '.$record->expires_at->format('d M Y, h:i A')
                        : 'No expiry'),

                ToggleColumn::make('is_active')
                    ->label('Active'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('scope')
                    ->label('Product Scope')
                    ->options([
                        'all' => 'All Products (Allover)',
                        'specific' => 'Specific Product(s)',
                    ]),
                SelectFilter::make('discount_type')
                    ->label('Discount Type')
                    ->options([
                        'percentage' => 'Percentage (%)',
                        'fixed' => 'Fixed Amount (PKR)',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('Active Status'),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Edit / Track'),
                Action::make('resetUsage')
                    ->label('Reset Counter')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->visible(fn (Voucher $record): bool => (int) $record->used_count > 0)
                    ->requiresConfirmation()
                    ->modalHeading('Reset Voucher Usage Counter?')
                    ->modalDescription('This resets the used_count counter back to 0 so the voucher can be redeemed again.')
                    ->action(function (Voucher $record): void {
                        $record->update(['used_count' => 0]);
                        Notification::make()->title('Voucher usage counter reset to 0')->success()->send();
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
