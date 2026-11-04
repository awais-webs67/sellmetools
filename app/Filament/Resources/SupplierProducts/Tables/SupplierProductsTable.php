<?php

namespace App\Filament\Resources\SupplierProducts\Tables;

use App\Enums\PricingMode;
use App\Enums\SupplierOrderStatus;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\SupplierOrders\SupplierOrderResource;
use App\Filament\Support\GalleryPicker;
use App\Filament\Support\Listings;
use App\Filament\Support\Money;
use App\Models\Category;
use App\Models\Product;
use App\Models\SupplierOrder;
use App\Models\SupplierProduct;
use App\Services\ProductSeoOptimizer;
use App\Services\Suppliers\SupplierManager;
use App\Services\SupplierSyncService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SupplierProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->select([
                'id', 'supplier_id', 'external_id', 'name', 'cost', 'stock',
                'unlimited_stock', 'is_available', 'warranty', 'description', 'last_seen_at',
            ])->with('supplier')->withCount('listings'))
            ->defaultSort('cost', 'asc')
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('external_id')
                    ->label('Ext. ID')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->limit(38)
                    ->tooltip(fn (SupplierProduct $record): string => $record->name),
                TextColumn::make('cost')
                    ->label('Cost (Low → High)')
                    ->weight('bold')
                    ->formatStateUsing(fn (SupplierProduct $record): string => Money::pkr(round($record->costPkr(), 2)))
                    ->description(fn (SupplierProduct $record): string => Money::currency($record->cost, $record->supplier->currency))
                    ->sortable(),
                TextColumn::make('stock')
                    ->badge()
                    ->color(fn (SupplierProduct $record): string => ($record->unlimited_stock || (int) $record->stock > 0) ? 'success' : 'danger')
                    ->state(fn (SupplierProduct $record): string => $record->unlimited_stock ? '∞' : (string) (int) $record->stock)
                    ->alignCenter(),
                IconColumn::make('is_available')
                    ->label('Available')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('listings_count')
                    ->label('Linked')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label('Synced')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->relationship('supplier', 'name')
                    ->preload(),
                Filter::make('available')
                    ->label('In-stock only')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('is_available', true)
                        ->where(fn (Builder $q) => $q->where('unlimited_stock', true)->orWhere('stock', '>', 0))),
                Filter::make('unlisted')
                    ->label('Not yet listed')
                    ->query(fn (Builder $query): Builder => $query->doesntHave('listings')),
            ])
            ->recordActions([
                self::quickBuyDirectAction(),
                self::createListingAction()->button()->size('xs'),
                self::linkAction()->button()->size('xs'),
            ])
            ->toolbarActions([
                self::bulkCreateListingsAction(),
            ]);
    }

    protected static function quickBuyDirectAction(): Action
    {
        return Action::make('quickBuyDirect')
            ->label('Buy Direct')
            ->icon(Heroicon::OutlinedShoppingBag)
            ->color('success')
            ->button()
            ->size('xs')
            ->modalHeading(fn (SupplierProduct $record): string => "Buy Directly: {$record->name}")
            ->modalDescription(fn (SupplierProduct $record): string => sprintf(
                'Supplier: %s · Cost: Rs %s (%s) · Stock: %s. Record & credentials will be saved in Direct & Vendor Orders.',
                $record->supplier?->name,
                number_format($record->costPkr(), 0),
                Money::currency($record->cost, $record->supplier?->currency ?? 'USDT'),
                $record->unlimited_stock ? '∞' : (string) (int) $record->stock,
            ))
            ->modalSubmitActionLabel('Buy Now & Save Record')
            ->schema([
                TextInput::make('quantity')
                    ->label('Quantity')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required(),
                Select::make('save_to_product_id')
                    ->label('Also add delivered credentials to Own Stock Vault of (Optional)')
                    ->placeholder('Just save in Direct & Vendor Orders')
                    ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
            ])
            ->action(function (SupplierProduct $record, array $data): void {
                $supplier = $record->supplier;
                if (! $supplier) {
                    Notification::make()->title('Supplier not found')->danger()->send();

                    return;
                }

                $qty = max(1, (int) ($data['quantity'] ?? 1));
                $reqId = 'DIRECT-'.strtoupper(uniqid());

                $attempt = SupplierOrder::create([
                    'supplier_id' => $supplier->id,
                    'order_item_id' => null,
                    'supplier_product_id' => $record->id,
                    'request_id' => $reqId,
                    'quantity' => $qty,
                    'status' => SupplierOrderStatus::Pending,
                ]);

                try {
                    $res = app(SupplierManager::class)->driver($supplier)->placeOrder($record->external_id, $qty, $reqId);
                } catch (\Throwable $e) {
                    $errLower = strtolower($e->getMessage());
                    $isBridgeable = str_contains($errLower, 'cooldown')
                        || str_contains($errLower, 'timed out')
                        || str_contains($errLower, 'connection error')
                        || str_contains($errLower, 'failed to connect');

                    if ($isBridgeable) {
                        $attempt->update([
                            'status' => SupplierOrderStatus::Pending,
                            'error' => 'Relaying via Admin Browser Bridge...',
                            'response' => ['save_to_product_id' => $data['save_to_product_id'] ?? null],
                        ]);

                        Notification::make()
                            ->title("Connecting to {$supplier->name} via Browser Bridge...")
                            ->body('Your browser is completing the direct purchase now. Open Direct & Vendor Orders in 2 seconds to view your credentials!')
                            ->info()
                            ->actions([
                                Action::make('viewVendorOrders')
                                    ->label('Open Direct & Vendor Orders')
                                    ->url(SupplierOrderResource::getUrl('index', ['activeTab' => 'direct'])),
                            ])
                            ->send();

                        return;
                    }

                    $attempt->update([
                        'status' => SupplierOrderStatus::Failed,
                        'error' => $e->getMessage(),
                    ]);
                    Notification::make()->title('Direct order failed')->body($e->getMessage())->danger()->send();

                    return;
                }

                $cost = $res->total ?? ((float) $record->cost * max(1, count($res->items)));
                $rawWithItems = is_array($res->raw) ? $res->raw : [];
                $rawWithItems['delivered_items'] = $res->items;

                $attempt->update([
                    'status' => SupplierOrderStatus::Success,
                    'cost' => $cost,
                    'external_order_id' => $res->externalOrderId,
                    'response' => $rawWithItems,
                ]);

                if ($res->balanceAfter !== null) {
                    app(SupplierSyncService::class)->recordBalance($supplier, $res->balanceAfter);
                }

                if ($record->stock !== null && ! $record->unlimited_stock) {
                    $record->decrement('stock', min($record->stock, count($res->items)));
                }

                if (! empty($data['save_to_product_id'])) {
                    $product = Product::find($data['save_to_product_id']);
                    if ($product) {
                        foreach ($res->items as $line) {
                            $product->stockItems()->create(['content' => $line]);
                        }
                        Listings::refresh($product);
                    }
                }

                Notification::make()
                    ->title('Direct Order Saved! ('.count($res->items).' item(s))')
                    ->body(implode("\n", $res->items))
                    ->persistent()
                    ->success()
                    ->actions([
                        Action::make('viewVendorOrders')
                            ->label('Open Direct & Vendor Orders')
                            ->url(SupplierOrderResource::getUrl('index', ['activeTab' => 'direct'])),
                    ])
                    ->send();
            });
    }

    protected static function createListingAction(): Action
    {
        return Action::make('createListing')
            ->label('Create listing')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Create listing')
            ->fillForm(fn (SupplierProduct $record): array => [
                'name' => ProductSeoOptimizer::cleanProductTitle((string) $record->name),
                'pricing_mode' => PricingMode::MarkupPercent->value,
                'markup_value' => 20,
                'warranty' => $record->warranty,
                'description' => filled($record->description) && $record->description === strip_tags((string) $record->description)
                    ? nl2br(e((string) $record->description))
                    : $record->description,
                'is_active' => true,
            ])
            ->schema(fn (SupplierProduct $record): array => [
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Select::make('category_id')
                        ->label('Category')
                        ->options(fn (): array => Category::query()->orderBy('sort')->pluck('name', 'id')->all())
                        ->searchable(),
                    Toggle::make('is_active')
                        ->label('Active on storefront')
                        ->inline(false),
                    GalleryPicker::make('image', 'Product Picture', 'gallery'),
                ]),
                Section::make('Pricing')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        Select::make('pricing_mode')
                            ->options(PricingMode::options())
                            ->required()
                            ->live(),
                        TextInput::make('fixed_price')
                            ->label('Fixed price (PKR)')
                            ->rule('numeric')
                            ->rule('min:0')
                            ->live(debounce: 250)
                            ->visible(fn (Get $get): bool => $get('pricing_mode') === PricingMode::Fixed->value)
                            ->required(fn (Get $get): bool => $get('pricing_mode') === PricingMode::Fixed->value),
                        TextInput::make('markup_value')
                            ->label(fn (Get $get): string => $get('pricing_mode') === PricingMode::MarkupPercent->value ? 'Markup (%)' : 'Markup (PKR)')
                            ->rule('numeric')
                            ->rule('min:0')
                            ->live(debounce: 250)
                            ->visible(fn (Get $get): bool => $get('pricing_mode') !== PricingMode::Fixed->value)
                            ->required(fn (Get $get): bool => $get('pricing_mode') !== PricingMode::Fixed->value),
                        TextEntry::make('cost_preview')
                            ->label('Supplier cost')
                            ->state(Money::currency($record->cost, $record->supplier->currency).' ≈ '.Money::pkr(round($record->costPkr(), 2))),
                        TextEntry::make('price_preview')
                            ->label('Selling price')
                            ->weight('bold')
                            ->color('primary')
                            ->state(fn (Get $get): string => self::pricePreview($record->costPkr(), $get('pricing_mode'), $get('fixed_price'), $get('markup_value'))),
                    ]),
                TextInput::make('short_description')
                    ->label('Short Tag / Subtitle (optional)')
                    ->maxLength(500),
                Textarea::make('warranty')
                    ->label('Warranty Terms')
                    ->rows(2),
                RichEditor::make('description')
                    ->label('Full Description')
                    ->columnSpanFull(),
            ])
            ->action(function (SupplierProduct $record, array $data): void {
                $product = Listings::createFromSupplierProduct($record, $data);

                Notification::make()
                    ->title('Listing created')
                    ->body("{$product->name}: ".Money::pkr($product->price))
                    ->success()
                    ->actions([
                        Action::make('edit')
                            ->label('Open product')
                            ->url(ProductResource::getUrl('edit', ['record' => $product])),
                    ])
                    ->send();
            });
    }

    protected static function linkAction(): Action
    {
        return Action::make('link')
            ->label('Link to product')
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->modalDescription('Links this supplier item to an existing storefront product. The product will automatically use the cheapest in-stock supplier and combine stock across all linked suppliers.')
            ->schema([
                Select::make('product_id')
                    ->label('Select Existing Storefront Product')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => Product::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                Toggle::make('copy_details')
                    ->label('Overwrite product Description & Warranty with this supplier’s info')
                    ->helperText('Leave OFF (recommended) to keep your existing product title, image, description & warranty untouched.')
                    ->default(false),
            ])
            ->action(function (SupplierProduct $record, array $data): void {
                $product = Product::query()->findOrFail($data['product_id']);

                $alreadyAttached = $product->sources()
                    ->where(function ($q) use ($record): void {
                        $q->where('supplier_products.id', $record->id)
                            ->orWhere('supplier_products.supplier_id', $record->supplier_id);
                    })
                    ->exists();

                if ($alreadyAttached) {
                    Notification::make()
                        ->title('Supplier Already Attached!')
                        ->body(($record->supplier?->name ?? 'This supplier')." is already attached to \"{$product->name}\". Cannot attach the same supplier a 2nd time.")
                        ->warning()
                        ->send();

                    return;
                }

                if (! empty($data['copy_details'])) {
                    $product->update(array_filter([
                        'description' => $record->description ?: $product->description,
                        'warranty' => $record->warranty ?: $product->warranty,
                    ]));
                }
                Listings::link($record, $product);

                Notification::make()
                    ->title("Linked to {$product->name}")
                    ->body('Cheapest active price is now '.Money::pkr($product->price).' (Total stock: '.($product->stock_unlimited ? '∞' : $product->available_stock).').')
                    ->success()
                    ->send();
            });
    }

    protected static function bulkCreateListingsAction(): BulkAction
    {
        return BulkAction::make('createListings')
            ->label('Create listings')
            ->icon(Heroicon::OutlinedSquaresPlus)
            ->modalDescription('Creates one product per selected supplier item using the same pricing rule.')
            ->schema([
                Select::make('category_id')
                    ->label('Category')
                    ->options(fn (): array => Category::query()->orderBy('sort')->pluck('name', 'id')->all())
                    ->searchable(),
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Select::make('pricing_mode')
                        ->options([
                            PricingMode::MarkupPercent->value => PricingMode::MarkupPercent->label(),
                            PricingMode::MarkupAmount->value => PricingMode::MarkupAmount->label(),
                        ])
                        ->default(PricingMode::MarkupPercent->value)
                        ->required(),
                    TextInput::make('markup_value')
                        ->label('Markup')
                        ->numeric()
                        ->minValue(0)
                        ->default(20)
                        ->required(),
                ]),
                Toggle::make('skip_listed')
                    ->label('Skip items that already have a listing')
                    ->default(true),
                Toggle::make('is_active')
                    ->label('Activate listings')
                    ->default(true),
            ])
            ->action(function (Collection $records, array $data): void {
                $created = 0;
                $skipped = 0;

                /** @var SupplierProduct $record */
                foreach ($records as $record) {
                    if ($data['skip_listed'] && $record->listings()->exists()) {
                        $skipped++;

                        continue;
                    }

                    Listings::createFromSupplierProduct($record, [
                        'category_id' => $data['category_id'] ?? null,
                        'pricing_mode' => $data['pricing_mode'],
                        'markup_value' => $data['markup_value'],
                        'is_active' => $data['is_active'],
                    ]);
                    $created++;
                }

                Notification::make()
                    ->title("{$created} listing(s) created")
                    ->body($skipped > 0 ? "{$skipped} already-listed item(s) skipped." : null)
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    protected static function pricePreview(float $costPkr, ?string $mode, mixed $fixedPrice, mixed $markup): string
    {
        if (blank($mode)) {
            return '-';
        }

        $price = Listings::previewPrice(round($costPkr, 2), $mode, $fixedPrice, $markup);
        $profit = $price - $costPkr;

        return Money::pkr($price).'  (profit '.Money::pkr(round($profit, 2)).')';
    }
}
