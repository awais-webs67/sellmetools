<?php

namespace App\Filament\Resources\SupplierProducts\Tables;

use App\Enums\PricingMode;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\GalleryPicker;
use App\Filament\Support\Listings;
use App\Filament\Support\Money;
use App\Models\Category;
use App\Models\Product;
use App\Models\SupplierProduct;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
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
            ->poll('10s')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('supplier')->withCount('listings'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->sortable(),
                TextColumn::make('external_id')
                    ->label('Ext. ID')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('cost')
                    ->formatStateUsing(fn (SupplierProduct $record): string => Money::currency($record->cost, $record->supplier->currency))
                    ->description(fn (SupplierProduct $record): string => Money::pkr(round($record->costPkr(), 2)))
                    ->sortable(),
                TextColumn::make('stock')
                    ->state(fn (SupplierProduct $record): string => $record->unlimited_stock ? '∞' : (string) (int) $record->stock)
                    ->alignCenter(),
                IconColumn::make('is_available')
                    ->label('Available')
                    ->boolean(),
                TextColumn::make('listings_count')
                    ->label('Listings')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->relationship('supplier', 'name')
                    ->preload(),
                Filter::make('available')
                    ->label('Available only')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query
                        ->where('is_available', true)
                        ->where(fn (Builder $q) => $q->where('unlimited_stock', true)->orWhere('stock', '>', 0))),
                Filter::make('unlisted')
                    ->label('Not yet listed')
                    ->query(fn (Builder $query): Builder => $query->doesntHave('listings')),
            ])
            ->recordActions([
                self::createListingAction(),
                self::linkAction(),
            ])
            ->toolbarActions([
                self::bulkCreateListingsAction(),
            ]);
    }

    protected static function createListingAction(): Action
    {
        return Action::make('createListing')
            ->label('Create listing')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Create listing')
            ->fillForm(fn (SupplierProduct $record): array => [
                'name' => $record->name,
                'pricing_mode' => PricingMode::MarkupPercent->value,
                'markup_value' => 20,
                'warranty' => $record->warranty,
                'description' => $record->description,
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
                            ->live(debounce: 500)
                            ->visible(fn (Get $get): bool => $get('pricing_mode') === PricingMode::Fixed->value)
                            ->required(fn (Get $get): bool => $get('pricing_mode') === PricingMode::Fixed->value),
                        TextInput::make('markup_value')
                            ->label(fn (Get $get): string => $get('pricing_mode') === PricingMode::MarkupPercent->value ? 'Markup (%)' : 'Markup (PKR)')
                            ->rule('numeric')
                            ->rule('min:0')
                            ->live(debounce: 500)
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
                    ->maxLength(500),
                Textarea::make('warranty')
                    ->rows(2),
                Textarea::make('description')
                    ->rows(4),
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
            ->schema([
                Select::make('product_id')
                    ->label('Product')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Product::query()
                        ->where('name', 'like', "%{$search}%")
                        ->orderBy('name')
                        ->limit(50)
                        ->pluck('name', 'id')
                        ->all())
                    ->options(fn (): array => Product::query()->latest()->limit(50)->pluck('name', 'id')->all()),
            ])
            ->action(function (SupplierProduct $record, array $data): void {
                $product = Product::query()->findOrFail($data['product_id']);
                Listings::link($record, $product);

                Notification::make()
                    ->title("Linked to {$product->name}")
                    ->body('Price is now '.Money::pkr($product->price).'.')
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
                Grid::make(2)->schema([
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
