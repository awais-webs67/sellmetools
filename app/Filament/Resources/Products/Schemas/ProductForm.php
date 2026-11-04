<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\PricingMode;
use App\Filament\Support\GalleryPicker;
use App\Filament\Support\Listings;
use App\Filament\Support\Money;
use App\Models\Product;
use App\Models\Setting;
use App\Services\PriceCalculator;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Product & Store Status')
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                    ->schema([
                        TextInput::make('name')
                            ->label('Product Name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(['default' => 1, 'sm' => 2]),
                        Select::make('category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Category Name')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug((string) $state))),
                                TextInput::make('slug')
                                    ->label('URL Slug')
                                    ->required()
                                    ->maxLength(255)
                                    ->alphaDash()
                                    ->unique('categories', 'slug', ignoreRecord: true),
                                TextInput::make('icon')
                                    ->label('Icon / Emoji (Optional)')
                                    ->placeholder('e.g. 🤖 or 🎨')
                                    ->maxLength(255),
                                Toggle::make('is_active')
                                    ->label('Active on Storefront')
                                    ->default(true),
                            ]),
                        Grid::make(2)
                            ->schema([
                                Toggle::make('is_active')
                                    ->label('Live on Site')
                                    ->default(true),
                                Toggle::make('is_featured')
                                    ->label('Featured'),
                            ]),
                        TextEntry::make('disabled_reason')
                            ->label('Auto-disabled alert')
                            ->color('danger')
                            ->visible(fn (?Product $record): bool => filled($record?->disabled_reason))
                            ->columnSpanFull(),
                    ]),

                Section::make('Pricing & 2-Way Profit Calculator')
                    ->visible(fn (): bool => ! auth()->user()?->isSeoStaff())
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])
                            ->columnSpanFull()
                            ->schema([
                                Toggle::make('use_overall_supplier_markup')
                                    ->label('🟢 Apply Overall Profit % to All Suppliers')
                                    ->helperText('ON = All linked suppliers use this overall profit. Turn OFF to set separate profit % or price for each supplier below.')
                                    ->default(true)
                                    ->live(),
                                Toggle::make('is_free')
                                    ->label('🎁 Free Product / Giveaway (Rs 0)')
                                    ->helperText('Turn ON to let customers claim for free without payment.')
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set): void {
                                        if ($state) {
                                            $set('pricing_mode', PricingMode::Fixed->value);
                                            $set('fixed_price', 0);
                                        }
                                    }),
                            ]),
                        Select::make('pricing_mode')
                            ->label('Pricing Rule')
                            ->options(PricingMode::options())
                            ->default(PricingMode::MarkupPercent->value)
                            ->required()
                            ->live()
                            ->visible(fn (Get $get): bool => ! (bool) $get('is_free')),
                        TextInput::make('markup_value')
                            ->label(fn (Get $get): string => self::mode($get) === PricingMode::MarkupPercent ? 'Profit Percentage (%)' : 'Profit Amount (PKR)')
                            ->suffix(fn (Get $get): ?string => self::mode($get) === PricingMode::MarkupPercent ? '%' : 'Rs')
                            ->placeholder('20')
                            ->rule('numeric')
                            ->rule('min:0')
                            ->default(20)
                            ->live(debounce: 250)
                            ->afterStateUpdated(function ($state, callable $set, Get $get, ?Product $record): void {
                                if ($state === null || $state === '' || ! is_numeric($state)) {
                                    return;
                                }
                                $cost = self::liveCost($record, $get);
                                if ($cost !== null && $cost > 0) {
                                    $sell = Listings::previewPrice($cost, self::mode($get), $get('fixed_price'), (float) $state);
                                    $set('calculated_sell_price_input', round($sell, 0));
                                }
                            })
                            ->visible(fn (Get $get): bool => ! (bool) $get('is_free') && self::mode($get) !== PricingMode::Fixed),
                        TextInput::make('calculated_sell_price_input')
                            ->label('Total Sell Price (PKR)')
                            ->prefix('Rs')
                            ->placeholder('Auto-calculated or type price')
                            ->numeric()
                            ->minValue(0)
                            ->dehydrated(false)
                            ->live(debounce: 250)
                            ->afterStateHydrated(function (TextInput $component, ?Product $record, Get $get): void {
                                if ($record) {
                                    $component->state(round((float) $record->price, 0));
                                }
                            })
                            ->afterStateUpdated(function ($state, callable $set, Get $get, ?Product $record): void {
                                if ($state === null || $state === '' || ! is_numeric($state)) {
                                    return;
                                }
                                $sell = max(0.0, (float) $state);
                                $cost = self::liveCost($record, $get);
                                if ($cost !== null && $cost > 0) {
                                    if (self::mode($get) === PricingMode::MarkupPercent) {
                                        $pct = round((($sell - $cost) / $cost) * 100, 2);
                                        $set('markup_value', max(0.0, $pct));
                                    } elseif (self::mode($get) === PricingMode::MarkupAmount) {
                                        $set('markup_value', max(0.0, round($sell - $cost, 2)));
                                    }
                                }
                            })
                            ->helperText('Type Sell Price here → Profit % updates automatically!')
                            ->visible(fn (Get $get): bool => ! (bool) $get('is_free') && self::mode($get) !== PricingMode::Fixed),
                        TextInput::make('fixed_price')
                            ->label('Fixed Selling Price (PKR)')
                            ->prefix('Rs')
                            ->placeholder('550')
                            ->rule('numeric')
                            ->rule('min:0')
                            ->default(0)
                            ->live(debounce: 250)
                            ->visible(fn (Get $get): bool => ! (bool) $get('is_free') && self::mode($get) === PricingMode::Fixed)
                            ->required(fn (Get $get): bool => ! (bool) $get('is_free') && self::mode($get) === PricingMode::Fixed),
                        TextInput::make('cost_price')
                            ->label('Source Cost (PKR)')
                            ->prefix('Rs')
                            ->placeholder('Auto from #1 supplier')
                            ->rule('numeric')
                            ->rule('min:0')
                            ->live(debounce: 250)
                            ->visible(fn (Get $get): bool => ! (bool) $get('is_free')),
                        TextInput::make('compare_at_price')
                            ->label('Old Strike Price (PKR - Optional)')
                            ->prefix('Rs')
                            ->placeholder('800')
                            ->rule('numeric')
                            ->rule('min:0'),
                        Grid::make(['default' => 2, 'sm' => 4])
                            ->columnSpanFull()
                            ->schema([
                                TextEntry::make('supplier_cost_preview')
                                    ->label('#1 Cheapest Cost')
                                    ->state(fn (?Product $record, Get $get): string => self::formatSupplierCost($record, $get)),
                                TextEntry::make('price')
                                    ->label('Final Store Price')
                                    ->weight('bold')
                                    ->color('primary')
                                    ->state(fn (?Product $record, Get $get): string => Money::pkr(self::liveSellingPrice($record, $get))),
                                TextEntry::make('profit')
                                    ->label('Your Net Profit')
                                    ->weight('bold')
                                    ->color('success')
                                    ->state(function (?Product $record, Get $get): string {
                                        $cost = self::liveCost($record, $get);
                                        if ($cost === null) {
                                            return '-';
                                        }
                                        $sell = self::liveSellingPrice($record, $get);
                                        $profit = round($sell - $cost, 0);
                                        $pct = $cost > 0 ? round(($profit / $cost) * 100, 1) : 0;

                                        return Money::pkr($profit)." ({$pct}%)";
                                    }),
                                TextEntry::make('stock')
                                    ->label('Total Stock')
                                    ->state(fn (?Product $record): string => $record?->stock_unlimited ? '∞' : (string) ($record?->available_stock ?? 0)),
                            ]),
                    ]),

                Section::make('🖼️ Picture, Description & Warranty')
                    ->collapsible()
                    ->collapsed(fn (?Product $record): bool => $record !== null)
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        GalleryPicker::make('image', 'Product Picture', 'gallery'),
                        Grid::make(1)
                            ->schema([
                                TextInput::make('short_description')
                                    ->label('Short Subtitle (optional)')
                                    ->placeholder('e.g. Instant Activation · Full Warranty')
                                    ->maxLength(500),
                                TextInput::make('slug')
                                    ->label('URL Slug (optional)')
                                    ->maxLength(255)
                                    ->alphaDash()
                                    ->unique(ignoreRecord: true)
                                    ->helperText('Leave blank to auto-generate.'),
                                Grid::make(2)
                                    ->schema([
                                        TextInput::make('max_per_order')
                                            ->label('Max Qty Per Order')
                                            ->rule('integer')
                                            ->rule('min:1')
                                            ->default(5)
                                            ->required(),
                                        TextInput::make('sort')
                                            ->label('Sort Order')
                                            ->rule('integer')
                                            ->rule('min:0')
                                            ->default(0)
                                            ->required(),
                                    ]),
                            ]),
                        Textarea::make('warranty')
                            ->label('Warranty Terms')
                            ->rows(2)
                            ->columnSpanFull(),
                        RichEditor::make('description')
                            ->label('Full Description')
                            ->columnSpanFull(),
                    ]),

                Section::make('📦 Delivery Method & .TXT Stock Vault (Click to Expand)')
                    ->visible(fn (): bool => ! auth()->user()?->isSeoStaff())
                    ->collapsible()
                    ->collapsed(fn (?Product $record): bool => $record !== null)
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Select::make('delivery_type')
                            ->label('Delivery Method')
                            ->options(Product::deliveryTypeOptions())
                            ->default('auto')
                            ->required()
                            ->live()
                            ->columnSpanFull(),

                        TextInput::make('stock_limit')
                            ->label('Stock Limit / Max Claims (Optional — Leave Blank for Unlimited ∞)')
                            ->placeholder('Leave blank for Unlimited (∞), or e.g. 50')
                            ->numeric()
                            ->minValue(0)
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => in_array((string) ($get('delivery_type') ?: 'auto'), ['instant_link', 'manual_admin', 'whatsapp_handover'], true)),

                        TextInput::make('customer_input_label')
                            ->label('📝 Ask Customer for Activation Data After Payment (Optional)')
                            ->placeholder('e.g. Enter your Email Address where we should activate your subscription')
                            ->maxLength(500)
                            ->columnSpanFull(),

                        Select::make('quick_stock_separator')
                            ->label('Quick .TXT Stock Separator Mode')
                            ->options([
                                'auto' => '✨ Smart Auto-Detect (Splits by --- or === if present, otherwise 1 Line = 1 PC)',
                                'separator' => '✂️ Multi-Line Credentials Separated by --- or ===',
                                'blank_line' => '📄 Multi-Line Credentials Separated by Blank Empty Line',
                                'line' => '📏 Single-Line Credentials (1 Line = 1 PC)',
                            ])
                            ->default('auto')
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => ((string) ($get('delivery_type') ?: 'auto')) === 'auto'),

                        FileUpload::make('quick_stock_file')
                            ->label('Quick Upload .TXT Stock File (Optional)')
                            ->disk('local')
                            ->directory('tmp-stock')
                            ->acceptedFileTypes(['text/plain', '.txt'])
                            ->maxSize(5120)
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => ((string) ($get('delivery_type') ?: 'auto')) === 'auto'),

                        Textarea::make('quick_stock_text')
                            ->label(fn (Get $get): string => ((string) $get('delivery_type')) === 'instant_link'
                                ? '🔗 Paste Reusable Course Link / Download URL'
                                : 'Or Quick Paste Stock Credentials (Added to Vault on Save)')
                            ->rows(4)
                            ->dehydrated(false)
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => in_array((string) ($get('delivery_type') ?: 'auto'), ['auto', 'instant_link'], true)),
                    ]),

                Section::make('🔍 SEO & Google Search (Auto-Generated if Left Blank)')
                    ->collapsible()
                    ->collapsed(fn (): bool => ! auth()->user()?->isSeoStaff())
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('meta_title')
                            ->label('Custom SEO Meta Title')
                            ->placeholder('Auto: ChatGPT Plus Price in Pakistan | Buy Online')
                            ->maxLength(255),
                        TextInput::make('meta_keywords')
                            ->label('Custom SEO Keywords')
                            ->placeholder('chatgpt plus pakistan, buy chatgpt easypaisa')
                            ->maxLength(500),
                        Textarea::make('meta_description')
                            ->label('Custom SEO Meta Description')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected static function mode(Get $get): PricingMode
    {
        $mode = $get('pricing_mode');

        return $mode instanceof PricingMode ? $mode : (PricingMode::tryFrom((string) $mode) ?? PricingMode::Fixed);
    }

    protected static function liveCost(?Product $record, ?Get $get = null): ?float
    {
        if ($get !== null) {
            $typedCost = $get('cost_price');
            if ($typedCost !== null && $typedCost !== '' && is_numeric($typedCost)) {
                return round((float) $typedCost, 2);
            }
        }

        if (! $record) {
            return null;
        }

        if ($record->cost_price !== null) {
            return (float) $record->cost_price;
        }

        $cheapest = app(PriceCalculator::class)->cheapestCost($record);

        return $cheapest !== null ? round($cheapest, 2) : null;
    }

    protected static function formatSupplierCost(?Product $record, ?Get $get = null): string
    {
        $costPkr = self::liveCost($record, $get);
        if ($costPkr === null) {
            return '-';
        }

        $usd = null;
        if ($record) {
            $source = app(PriceCalculator::class)->rankedSources($record)->first()
                ?? $record->activeSources()
                    ->with('supplier')
                    ->whereHas('supplier', fn ($q) => $q->where('is_active', true))
                    ->get()
                    ->sortBy(fn ($s) => $s->costPkr())
                    ->first();

            if ($source && strtoupper((string) $source->supplier?->currency) !== 'PKR') {
                $usd = (float) $source->cost;
            }
        }

        if ($usd === null) {
            $usd = $costPkr / max(1.0, Setting::usdtRate());
        }

        $usdFormatted = preg_replace('/(\.\d{2}\d*?)0+$/', '$1', number_format($usd, 4));

        return Money::pkr($costPkr)." (\${$usdFormatted})";
    }

    protected static function liveSellingPrice(?Product $record, Get $get): float
    {
        if ((bool) $get('is_free')) {
            return 0.0;
        }

        return Listings::previewPrice(
            self::liveCost($record, $get),
            self::mode($get),
            $get('fixed_price'),
            $get('markup_value'),
        );
    }
}
