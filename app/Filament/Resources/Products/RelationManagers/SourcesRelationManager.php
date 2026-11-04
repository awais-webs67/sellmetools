<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Enums\PricingMode;
use App\Filament\Support\Listings;
use App\Filament\Support\Money;
use App\Models\Product;
use App\Models\SupplierProduct;
use App\Services\PriceCalculator;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Supplier products this listing can be fulfilled from (cheapest active source wins). */
class SourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'sources';

    protected static ?string $title = 'Supplier sources';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (SupplierProduct $record): string => sprintf(
                '%s: %s (%s / Rs %s)',
                $record->supplier?->name,
                $record->name,
                Money::currency($record->cost, $record->supplier?->currency),
                number_format($record->costPkr(), 0),
            ))
            ->modifyQueryUsing(fn (Builder $query) => $query->with('supplier'))
            ->columns([
                TextColumn::make('supplier.name')
                    ->label('Supplier / Priority')
                    ->weight('bold')
                    ->description(function (SupplierProduct $record): string {
                        $winning = app(PriceCalculator::class)->winningSource($this->owner());
                        if ($winning && (int) $winning->id === (int) $record->id) {
                            return '⭐ #1 Active Storefront Source (Cheapest)';
                        }

                        return $record->pivot?->is_active ? 'Backup Supplier (Standby)' : 'Disabled';
                    }),
                TextColumn::make('name')
                    ->label('Vendor Item')
                    ->searchable()
                    ->limit(36)
                    ->tooltip(fn (SupplierProduct $record): string => $record->name),
                TextColumn::make('cost')
                    ->label('Vendor Cost')
                    ->weight('semibold')
                    ->formatStateUsing(fn (SupplierProduct $record): string => Money::pkr(round($record->costPkr(), 2)))
                    ->description(fn (SupplierProduct $record): string => Money::currency($record->cost, $record->supplier?->currency ?? 'USDT')),
                TextColumn::make('profit_rule')
                    ->label('Margin Rule')
                    ->badge()
                    ->color(fn (SupplierProduct $record): string => filled($record->pivot?->pricing_mode) ? 'primary' : 'gray')
                    ->state(fn (SupplierProduct $record): string => app(PriceCalculator::class)->sourceMarginLabel($this->owner(), $record)),
                TextColumn::make('calculated_price')
                    ->label('Price & Profit')
                    ->weight('bold')
                    ->state(function (SupplierProduct $record): string {
                        $price = app(PriceCalculator::class)->sellingPriceForSource($this->owner(), $record);

                        return Money::pkr($price);
                    })
                    ->description(function (SupplierProduct $record): string {
                        $cost = round($record->costPkr(), 2);
                        $price = app(PriceCalculator::class)->sellingPriceForSource($this->owner(), $record, $cost);
                        $profit = round($price - $cost, 2);
                        $pct = $cost > 0 ? round(($profit / $cost) * 100, 1) : 0;

                        return ($profit >= 0 ? 'Profit: +' : 'Loss: ').Money::pkr($profit)." ({$pct}%)";
                    }),
                TextColumn::make('stock')
                    ->label('Stock')
                    ->badge()
                    ->color(fn (SupplierProduct $record): string => ($record->unlimited_stock || (int) $record->stock > 0) ? 'success' : 'danger')
                    ->state(fn (SupplierProduct $record): string => $record->unlimited_stock ? '∞' : (string) (int) $record->stock),
                IconColumn::make('is_available')
                    ->label('Available')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('source_active')
                    ->label('Enabled')
                    ->state(fn (SupplierProduct $record): bool => (bool) $record->pivot?->is_active)
                    ->boolean(),
                TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Attach supplier product')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'external_id'])
                    ->recordSelectOptionsQuery(fn (Builder $query) => $query->with('supplier')->orderBy('name'))
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Select::make('pricing_mode')
                            ->label('Profit / Margin Mode for This Supplier')
                            ->options([
                                '' => 'Use Product Overall Margin (Default)',
                                PricingMode::MarkupPercent->value => 'Custom Profit Percentage (%) for this Supplier',
                                PricingMode::MarkupAmount->value => 'Custom Profit Amount (PKR) for this Supplier',
                                PricingMode::Fixed->value => 'Custom Fixed Selling Price (PKR) for this Supplier',
                            ])
                            ->default('')
                            ->selectablePlaceholder(false)
                            ->live(),
                        TextInput::make('markup_value')
                            ->label(fn (Get $get): string => match ($get('pricing_mode')) {
                                PricingMode::MarkupPercent->value => 'Custom Profit % (e.g. 30 for +30%)',
                                PricingMode::MarkupAmount->value => 'Custom Profit in PKR (e.g. 250 for +Rs 250)',
                                PricingMode::Fixed->value => 'Custom Fixed Selling Price in PKR',
                                default => 'Custom Value',
                            })
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => filled($get('pricing_mode')))
                            ->required(fn (Get $get): bool => filled($get('pricing_mode'))),
                    ])
                    ->after(fn () => $this->refreshOwner()),
            ])
            ->recordActions([
                Action::make('setMargin')
                    ->label('Profit %')
                    ->tooltip('Set separate profit % or markup when this supplier is active')
                    ->icon(Heroicon::OutlinedCalculator)
                    ->color('primary')
                    ->button()
                    ->size('xs')
                    ->modalHeading(fn (SupplierProduct $record): string => 'Supplier Profit Margin: '.($record->supplier?->name ?? 'Supplier'))
                    ->modalDescription(fn (SupplierProduct $record): string => sprintf(
                        'Supplier Cost: Rs %s (%s). Storefront always selects the lowest-cost active supplier first and applies that supplier’s profit margin.',
                        number_format($record->costPkr(), 2),
                        Money::currency($record->cost, $record->supplier?->currency ?? 'USDT'),
                    ))
                    ->fillForm(fn (SupplierProduct $record): array => [
                        'pricing_mode' => (string) ($record->pivot?->pricing_mode ?? ''),
                        'markup_value' => $record->pivot?->markup_value !== null ? (float) $record->pivot->markup_value : (float) ($this->owner()->markup_value ?? 20),
                    ])
                    ->schema([
                        Select::make('pricing_mode')
                            ->label('Margin Mode for This Supplier')
                            ->options([
                                '' => 'Use Product Overall Margin (Default)',
                                PricingMode::MarkupPercent->value => 'Custom Profit Percentage (%)',
                                PricingMode::MarkupAmount->value => 'Custom Profit Amount (PKR)',
                                PricingMode::Fixed->value => 'Custom Fixed Selling Price (PKR)',
                            ])
                            ->default('')
                            ->selectablePlaceholder(false)
                            ->live(),
                        TextInput::make('markup_value')
                            ->label(fn (Get $get): string => match ($get('pricing_mode')) {
                                PricingMode::MarkupPercent->value => 'Profit Percentage (%) (e.g. 20 or 30)',
                                PricingMode::MarkupAmount->value => 'Profit Amount in PKR (e.g. 200)',
                                PricingMode::Fixed->value => 'Fixed Selling Price in PKR (e.g. 1200)',
                                default => 'Value',
                            })
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => filled($get('pricing_mode')))
                            ->required(fn (Get $get): bool => filled($get('pricing_mode'))),
                    ])
                    ->action(function (SupplierProduct $record, array $data): void {
                        $mode = trim((string) ($data['pricing_mode'] ?? ''));
                        $val = $mode !== '' && isset($data['markup_value']) && $data['markup_value'] !== ''
                            ? round((float) $data['markup_value'], 2)
                            : null;

                        $this->owner()->sources()->updateExistingPivot($record->getKey(), [
                            'pricing_mode' => $mode !== '' ? $mode : null,
                            'markup_value' => $val,
                        ]);

                        $this->refreshOwner();
                        Notification::make()
                            ->title('Supplier profit margin updated')
                            ->success()
                            ->send();
                    }),
                Action::make('useDetails')
                    ->label('Use Info')
                    ->tooltip('Copy this supplier’s description & warranty to the product')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->color('gray')
                    ->button()
                    ->size('xs')
                    ->requiresConfirmation()
                    ->modalDescription('Copies this supplier item’s Description and Warranty into your storefront Product.')
                    ->action(function (SupplierProduct $record): void {
                        $owner = $this->owner();
                        $owner->update([
                            'description' => $record->description ?: $owner->description,
                            'warranty' => $record->warranty ?: $owner->warranty,
                        ]);
                        $this->dispatch('product-refreshed');
                    }),
                Action::make('toggleSource')
                    ->label(fn (SupplierProduct $record): string => $record->pivot?->is_active ? 'Disable' : 'Enable')
                    ->icon(fn (SupplierProduct $record): Heroicon => $record->pivot?->is_active ? Heroicon::OutlinedPause : Heroicon::OutlinedPlay)
                    ->color(fn (SupplierProduct $record): string => $record->pivot?->is_active ? 'warning' : 'success')
                    ->button()
                    ->size('xs')
                    ->action(function (SupplierProduct $record): void {
                        $this->owner()->sources()->updateExistingPivot($record->getKey(), [
                            'is_active' => ! $record->pivot?->is_active,
                        ]);
                        $this->refreshOwner();
                    }),
                DetachAction::make()
                    ->button()
                    ->size('xs')
                    ->after(fn () => $this->refreshOwner()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->after(fn () => $this->refreshOwner()),
                ]),
            ]);
    }

    protected function owner(): Product
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        return $product;
    }

    protected function refreshOwner(): void
    {
        Listings::refresh($this->owner()->refresh());
        $this->dispatch('product-refreshed');
    }
}
