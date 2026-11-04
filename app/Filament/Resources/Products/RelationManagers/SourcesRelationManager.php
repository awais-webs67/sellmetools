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
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
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

    protected static ?string $title = 'Supplier Sources (Sorted: Cheapest Cost First)';

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
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('supplier')
                ->orderByDesc('product_sources.is_active')
                ->orderBy('supplier_products.cost', 'asc')
                ->orderBy('supplier_products.id', 'asc'))
            ->paginated(false)
            ->columns([
                TextColumn::make('supplier.name')
                    ->label('Priority & Supplier')
                    ->weight('bold')
                    ->formatStateUsing(function (SupplierProduct $record): string {
                        $ranked = $this->owner()->sources()
                            ->with('supplier')
                            ->get()
                            ->sortBy([
                                fn (SupplierProduct $a, SupplierProduct $b) => (int) ($b->pivot?->is_active ?? false) <=> (int) ($a->pivot?->is_active ?? false),
                                fn (SupplierProduct $a, SupplierProduct $b) => $a->costPkr() <=> $b->costPkr(),
                            ])
                            ->values();
                        $pos = $ranked->search(fn (SupplierProduct $s): bool => (int) $s->id === (int) $record->id);
                        $rankNum = $pos !== false ? ($pos + 1) : 1;

                        return "#{$rankNum} · ".($record->supplier?->name ?? 'Supplier');
                    })
                    ->description(function (SupplierProduct $record): string {
                        $winning = app(PriceCalculator::class)->winningSource($this->owner());
                        if ($winning && (int) $winning->id === (int) $record->id) {
                            return 'Active on Store (Lowest Cost)';
                        }

                        return $record->pivot?->is_active ? 'Standby Backup' : 'Disabled';
                    }),
                TextColumn::make('name')
                    ->label('Vendor Item')
                    ->searchable()
                    ->limit(32)
                    ->tooltip(fn (SupplierProduct $record): string => $record->name),
                TextColumn::make('cost')
                    ->label('Cost (Low → High)')
                    ->weight('bold')
                    ->formatStateUsing(fn (SupplierProduct $record): string => Money::pkr(round($record->costPkr(), 2)))
                    ->description(fn (SupplierProduct $record): string => Money::currency($record->cost, $record->supplier?->currency ?? 'USDT')),
                TextColumn::make('profit_rule')
                    ->label('Profit Mode')
                    ->badge()
                    ->color(fn (SupplierProduct $record): string => (! ($this->owner()->use_overall_supplier_markup ?? true) && filled($record->pivot?->pricing_mode)) ? 'primary' : 'gray')
                    ->state(fn (SupplierProduct $record): string => app(PriceCalculator::class)->sourceMarginLabel($this->owner(), $record)),
                TextColumn::make('calculated_price')
                    ->label('Sell Price & Profit')
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

                        return ($profit >= 0 ? '+'.Money::pkr($profit) : Money::pkr($profit))." ({$pct}%)";
                    }),
                TextColumn::make('stock')
                    ->label('Stock')
                    ->badge()
                    ->color(fn (SupplierProduct $record): string => ($record->unlimited_stock || (int) $record->stock > 0) ? 'success' : 'danger')
                    ->state(fn (SupplierProduct $record): string => $record->unlimited_stock ? '∞' : (string) (int) $record->stock),
                IconColumn::make('source_active')
                    ->label('Enabled')
                    ->state(fn (SupplierProduct $record): bool => (bool) $record->pivot?->is_active)
                    ->boolean(),
            ])
            ->headerActions([
                Action::make('toggleOverallMarkup')
                    ->label(fn (): string => ($this->owner()->use_overall_supplier_markup ?? true)
                        ? 'Overall Profit on All Suppliers: ON (Click to Turn OFF)'
                        : 'Custom Profit Per Supplier: ON (Click to Use Overall)')
                    ->icon(fn (): Heroicon => ($this->owner()->use_overall_supplier_markup ?? true)
                        ? Heroicon::OutlinedLockClosed
                        : Heroicon::OutlinedAdjustmentsHorizontal)
                    ->color(fn (): string => ($this->owner()->use_overall_supplier_markup ?? true) ? 'success' : 'warning')
                    ->button()
                    ->action(function (): void {
                        $owner = $this->owner();
                        $newState = ! ($owner->use_overall_supplier_markup ?? true);
                        $owner->update(['use_overall_supplier_markup' => $newState]);
                        $this->refreshOwner();

                        Notification::make()
                            ->title($newState
                                ? 'Overall Profit ON: All suppliers now use the product’s overall margin'
                                : 'Custom Per-Supplier Profit ON: You can now set separate profit % or price for each supplier')
                            ->color($newState ? 'success' : 'warning')
                            ->send();
                    }),
                AttachAction::make()
                    ->label('Attach Supplier')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'external_id'])
                    ->recordSelectOptionsQuery(function (Builder $query): Builder {
                        $attachedIds = $this->owner()->sources()->pluck('supplier_products.id')->all();
                        $attachedSupplierIds = $this->owner()->sources()->pluck('supplier_products.supplier_id')->filter()->unique()->all();

                        return $query
                            ->with('supplier')
                            ->when($attachedIds !== [], fn (Builder $q) => $q->whereNotIn('supplier_products.id', $attachedIds))
                            ->when($attachedSupplierIds !== [], fn (Builder $q) => $q->whereNotIn('supplier_products.supplier_id', $attachedSupplierIds))
                            ->orderBy('cost', 'asc');
                    })
                    ->before(function (AttachAction $action, array $data): void {
                        $spId = (int) ($data['recordId'] ?? 0);
                        if ($spId <= 0) {
                            return;
                        }
                        $sp = SupplierProduct::with('supplier')->find($spId);
                        if (! $sp) {
                            return;
                        }
                        $alreadyAttached = $this->owner()->sources()
                            ->where(function (Builder $q) use ($sp): void {
                                $q->where('supplier_products.id', $sp->id)
                                    ->orWhere('supplier_products.supplier_id', $sp->supplier_id);
                            })
                            ->exists();

                        if ($alreadyAttached) {
                            Notification::make()
                                ->title('Supplier Already Attached!')
                                ->body(($sp->supplier?->name ?? 'This supplier').' already has a product linked to "'.$this->owner()->name.'". Cannot attach a 2nd time.')
                                ->warning()
                                ->send();

                            $action->halt();
                        }
                    })
                    ->after(fn () => $this->refreshOwner()),
            ])
            ->recordActions([
                Action::make('setMargin')
                    ->label(fn (): string => ($this->owner()->use_overall_supplier_markup ?? true) ? 'Overall ON' : 'Set Profit / Price')
                    ->tooltip(fn (): string => ($this->owner()->use_overall_supplier_markup ?? true)
                        ? 'Turn OFF "Overall Profit on All Suppliers" button above to set custom profit for this supplier'
                        : 'Set Profit % or Total Sell Price for this supplier (2-way live calculator)')
                    ->icon(Heroicon::OutlinedCalculator)
                    ->color(fn (): string => ($this->owner()->use_overall_supplier_markup ?? true) ? 'gray' : 'primary')
                    ->disabled(fn (): bool => (bool) ($this->owner()->use_overall_supplier_markup ?? true))
                    ->button()
                    ->size('xs')
                    ->modalHeading(fn (SupplierProduct $record): string => 'Set Profit & Sell Price: '.($record->supplier?->name ?? 'Supplier'))
                    ->modalDescription(fn (SupplierProduct $record): string => sprintf(
                        'Supplier Cost: Rs %s (%s). Type either Profit %% OR Total Sell Price below — the other box updates automatically!',
                        number_format($record->costPkr(), 2),
                        Money::currency($record->cost, $record->supplier?->currency ?? 'USDT'),
                    ))
                    ->fillForm(function (SupplierProduct $record): array {
                        $cost = max(1.0, round($record->costPkr(), 2));
                        $currentPrice = app(PriceCalculator::class)->sellingPriceForSource($this->owner(), $record, $cost);
                        $pivotMode = (string) ($record->pivot?->pricing_mode ?? '');
                        $pivotVal = $record->pivot?->markup_value;

                        if ($pivotMode === PricingMode::MarkupPercent->value && $pivotVal !== null) {
                            $pct = round((float) $pivotVal, 2);
                        } else {
                            $pct = max(0.0, round((($currentPrice - $cost) / $cost) * 100, 2));
                        }

                        return [
                            'use_default_for_this_row' => $pivotMode === '',
                            'calc_mode' => $pivotMode === PricingMode::Fixed->value ? 'price' : 'percent',
                            'profit_percent' => $pct,
                            'sell_price_pkr' => round($currentPrice, 0),
                        ];
                    })
                    ->schema(function (SupplierProduct $record): array {
                        $cost = max(0.01, round($record->costPkr(), 2));

                        return [
                            Grid::make(['default' => 1, 'sm' => 2])
                                ->schema([
                                    TextInput::make('profit_percent')
                                        ->label('Profit Percentage (%)')
                                        ->suffix('%')
                                        ->numeric()
                                        ->minValue(0)
                                        ->live(debounce: 250)
                                        ->afterStateUpdated(function ($state, callable $set) use ($cost): void {
                                            if ($state === null || $state === '' || ! is_numeric($state)) {
                                                return;
                                            }
                                            $pct = max(0.0, (float) $state);
                                            $sell = round($cost * (1 + ($pct / 100)), 0);
                                            $set('sell_price_pkr', $sell);
                                            $set('calc_mode', 'percent');
                                            $set('use_default_for_this_row', false);
                                        })
                                        ->helperText('Type % here → Total Sell Price updates automatically.'),

                                    TextInput::make('sell_price_pkr')
                                        ->label('Total Sell Price (PKR)')
                                        ->prefix('Rs')
                                        ->numeric()
                                        ->minValue(0)
                                        ->live(debounce: 250)
                                        ->afterStateUpdated(function ($state, callable $set) use ($cost): void {
                                            if ($state === null || $state === '' || ! is_numeric($state)) {
                                                return;
                                            }
                                            $sell = max(0.0, (float) $state);
                                            $pct = $cost > 0 ? round((($sell - $cost) / $cost) * 100, 2) : 0.0;
                                            $set('profit_percent', max(0.0, $pct));
                                            $set('calc_mode', 'price');
                                            $set('use_default_for_this_row', false);
                                        })
                                        ->helperText('Type Sell Price here → Profit % updates automatically.'),
                                ]),

                            Grid::make(3)
                                ->schema([
                                    TextEntry::make('live_cost_box')
                                        ->label('Supplier Cost')
                                        ->state(Money::pkr($cost).' ('.Money::currency($record->cost, $record->supplier?->currency ?? 'USDT').')'),
                                    TextEntry::make('live_price_box')
                                        ->label('Store Sell Price')
                                        ->weight('bold')
                                        ->color('primary')
                                        ->state(fn (Get $get): string => Money::pkr((float) ($get('sell_price_pkr') ?: 0))),
                                    TextEntry::make('live_profit_box')
                                        ->label('Your Net Profit')
                                        ->weight('bold')
                                        ->color(fn (Get $get): string => ((float) ($get('sell_price_pkr') ?: 0) - $cost) >= 0 ? 'success' : 'danger')
                                        ->state(function (Get $get) use ($cost): string {
                                            $sell = (float) ($get('sell_price_pkr') ?: 0);
                                            $profit = round($sell - $cost, 0);
                                            $pct = (float) ($get('profit_percent') ?: 0);

                                            return ($profit >= 0 ? '+Rs ' : '-Rs ').number_format(abs($profit), 0)." ({$pct}%)";
                                        }),
                                ]),

                            Hidden::make('calc_mode')->default('percent'),

                            Toggle::make('use_default_for_this_row')
                                ->label('Use Product Overall Profit for this supplier instead of custom')
                                ->default(false),
                        ];
                    })
                    ->action(function (SupplierProduct $record, array $data): void {
                        if (! empty($data['use_default_for_this_row'])) {
                            $this->owner()->sources()->updateExistingPivot($record->getKey(), [
                                'pricing_mode' => null,
                                'markup_value' => null,
                            ]);
                        } else {
                            $calcMode = (string) ($data['calc_mode'] ?? 'percent');
                            if ($calcMode === 'price') {
                                $this->owner()->sources()->updateExistingPivot($record->getKey(), [
                                    'pricing_mode' => PricingMode::Fixed->value,
                                    'markup_value' => round((float) ($data['sell_price_pkr'] ?? 0), 2),
                                ]);
                            } else {
                                $this->owner()->sources()->updateExistingPivot($record->getKey(), [
                                    'pricing_mode' => PricingMode::MarkupPercent->value,
                                    'markup_value' => round((float) ($data['profit_percent'] ?? 0), 2),
                                ]);
                            }
                        }

                        $this->refreshOwner();
                        Notification::make()
                            ->title('Supplier profit & price saved!')
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
