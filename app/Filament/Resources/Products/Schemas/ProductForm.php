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
                Section::make('Product Information & Picture')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label('Product Name')
                            ->required()
                            ->maxLength(255),
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
                            ])
                            ->editOptionForm([
                                TextInput::make('name')
                                    ->label('Category Name')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('slug')
                                    ->label('URL Slug')
                                    ->required()
                                    ->maxLength(255)
                                    ->alphaDash()
                                    ->unique('categories', 'slug', ignoreRecord: true),
                                TextInput::make('icon')
                                    ->label('Icon / Emoji (Optional)')
                                    ->maxLength(255),
                                Toggle::make('is_active')
                                    ->label('Active on Storefront')
                                    ->default(true),
                            ]),
                        GalleryPicker::make('image', 'Product Picture', 'gallery'),
                        TextInput::make('short_description')
                            ->label('Short Tag / Subtitle (optional)')
                            ->placeholder('e.g. Instant Activation · Full Warranty')
                            ->maxLength(500),
                        TextInput::make('slug')
                            ->label('URL Slug (optional)')
                            ->maxLength(255)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->helperText('Leave blank to auto-generate.'),
                    ]),

                Section::make('Pricing & Profit Rule')
                    ->visible(fn (): bool => ! auth()->user()?->isSeoStaff())
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                    ->schema([
                        Toggle::make('is_free')
                            ->label('🎁 Free Product / Giveaway Tag (0 PKR · No Payment Needed)')
                            ->helperText('Turn ON to add the FREE tag to this product and let customers claim it without paying or entering a Transaction ID.')
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set): void {
                                if ($state) {
                                    $set('pricing_mode', PricingMode::Fixed->value);
                                    $set('fixed_price', 0);
                                }
                            })
                            ->columnSpanFull(),
                        Select::make('pricing_mode')
                            ->label('Pricing Rule')
                            ->options(PricingMode::options())
                            ->default(PricingMode::Fixed->value)
                            ->required()
                            ->live()
                            ->visible(fn (Get $get): bool => ! (bool) $get('is_free'))
                            ->columnSpan(['default' => 1, 'sm' => 2]),
                        TextInput::make('fixed_price')
                            ->label('Fixed Selling Price (PKR)')
                            ->placeholder('550')
                            ->rule('numeric')
                            ->rule('min:0')
                            ->default(0)
                            ->live(debounce: 250)
                            ->visible(fn (Get $get): bool => ! (bool) $get('is_free') && self::mode($get) === PricingMode::Fixed)
                            ->required(fn (Get $get): bool => ! (bool) $get('is_free') && self::mode($get) === PricingMode::Fixed),
                        TextInput::make('markup_value')
                            ->label(fn (Get $get): string => self::mode($get) === PricingMode::MarkupPercent ? 'Profit Markup (%)' : 'Profit Markup (PKR)')
                            ->placeholder('250')
                            ->rule('numeric')
                            ->rule('min:0')
                            ->default(0)
                            ->live(debounce: 250)
                            ->visible(fn (Get $get): bool => ! (bool) $get('is_free') && self::mode($get) !== PricingMode::Fixed),
                        TextInput::make('compare_at_price')
                            ->label('Old Price / Discount Strike (PKR)')
                            ->placeholder('800')
                            ->rule('numeric')
                            ->rule('min:0'),
                        Grid::make(['default' => 2, 'sm' => 4])
                            ->columnSpanFull()
                            ->schema([
                                TextEntry::make('cost_price')
                                    ->label('Supplier Cost')
                                    ->state(fn (?Product $record): string => self::formatSupplierCost($record)),
                                TextEntry::make('price')
                                    ->label('Final Selling Price')
                                    ->weight('bold')
                                    ->color('primary')
                                    ->state(fn (?Product $record, Get $get): string => Money::pkr(self::liveSellingPrice($record, $get))),
                                TextEntry::make('profit')
                                    ->label('Your Profit')
                                    ->state(function (?Product $record, Get $get): string {
                                        $cost = self::liveCost($record);
                                        if ($cost === null) {
                                            return '-';
                                        }

                                        return Money::pkr(round(self::liveSellingPrice($record, $get) - $cost, 2));
                                    }),
                                TextEntry::make('stock')
                                    ->label('Live Stock')
                                    ->state(fn (?Product $record): string => $record?->stock_unlimited ? '∞' : (string) ($record?->available_stock ?? 0)),
                            ]),
                    ]),

                Section::make('📦 Product Delivery Method & .TXT Stock Vault (For Supplier & Non-Supplier Products)')
                    ->visible(fn (): bool => ! auth()->user()?->isSeoStaff())
                    ->description('Choose how this product is delivered to the customer after payment is confirmed.')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Select::make('delivery_type')
                            ->label('Delivery Method')
                            ->options(Product::deliveryTypeOptions())
                            ->default('auto')
                            ->required()
                            ->live()
                            ->columnSpanFull()
                            ->helperText(fn (Get $get): string => match ((string) $get('delivery_type')) {
                                'instant_link' => '🔗 Reusable Course / Resource Link Mode: Paste your Course Link, Google Drive URL, or download note below — every customer receives that exact link/content automatically! Set a Stock Limit below or leave blank for Unlimited (∞).',
                                'manual_admin' => '👨‍💻 Non-Supplier Manual Mode: Once order is confirmed, you get an alert to paste & send credentials from Admin Panel or 1-Tap Page.',
                                'whatsapp_handover' => '📲 Non-Supplier WhatsApp Mode: Once order is confirmed, the customer sees a big "Receive Order on WhatsApp" button with their verified Order ID.',
                                default => '⚡ Auto Mode: Delivers from your uploaded .TXT / Own Stock Vault first, then falls back to linked Supplier API (if any). Sold items are locked so they are NEVER sold twice.',
                            }),

                        TextInput::make('stock_limit')
                            ->label('Stock Limit / Max Claims (Optional — Leave Blank for Unlimited ∞)')
                            ->placeholder('Leave blank for Unlimited (∞), or e.g. 50')
                            ->numeric()
                            ->minValue(0)
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => in_array((string) ($get('delivery_type') ?: 'auto'), ['instant_link', 'manual_admin', 'whatsapp_handover'], true))
                            ->helperText('Leave blank for Unlimited Stock (∞), OR enter a number (e.g. 20 or 100) to limit how many orders/claims are allowed before it automatically goes Out of Stock.'),

                        TextInput::make('customer_input_label')
                            ->label('📝 Ask Customer for Activation Data After Payment (Optional — For Custom / Account Activation Products)')
                            ->placeholder('e.g. Enter your Canva / ChatGPT Email Address (or Account Login) where we should activate your subscription')
                            ->maxLength(500)
                            ->columnSpanFull()
                            ->helperText('Leave blank for normal instant-delivery products. If you write a prompt here, the customer will see a text box on their Order Page to submit their Email / Account Details to you for custom activation & manual delivery!'),

                        Select::make('quick_stock_separator')
                            ->label('Quick .TXT Stock Separator Mode')
                            ->options([
                                'auto' => '✨ Smart Auto-Detect (Splits by --- or === if present, otherwise 1 Line = 1 PC)',
                                'separator' => '✂️ Multi-Line Credentials Separated by --- or === (Between Credential A & B)',
                                'blank_line' => '📄 Multi-Line Credentials Separated by Blank Empty Line',
                                'line' => '📏 Single-Line Credentials (1 Line = 1 PC, e.g. email:password)',
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
                            ->visible(fn (Get $get): bool => ((string) ($get('delivery_type') ?: 'auto')) === 'auto')
                            ->helperText('Upload a .txt file here to immediately add stock units when you click Save.'),

                        Textarea::make('quick_stock_text')
                            ->label(fn (Get $get): string => ((string) $get('delivery_type')) === 'instant_link'
                                ? '🔗 Paste Reusable Course Link / Download URL / Access Instructions'
                                : 'Or Quick Paste Stock Credentials (Optional: Added to Vault on Save)')
                            ->rows(5)
                            ->dehydrated(false)
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => in_array((string) ($get('delivery_type') ?: 'auto'), ['auto', 'instant_link'], true))
                            ->placeholder(fn (Get $get): string => ((string) $get('delivery_type')) === 'instant_link'
                                ? "Course Link: https://drive.google.com/drive/folders/...\nPasscode / Note: Join our community for updates!"
                                : "Email: userA@gmail.com\nPassword: pass123\n---\nEmail: userB@gmail.com\nPassword: pass456")
                            ->helperText(fn (Get $get): string => ((string) $get('delivery_type')) === 'instant_link'
                                ? 'Everything you paste here will be delivered as a single block to EVERY customer who orders/claims this product (Unlimited Stock).'
                                : 'Put --- on its own line between Account A and Account B for multi-line credentials. When a user orders 2 PCs, 2 blocks are extracted, delivered, and marked Sold forever.'),
                    ]),

                Section::make('Description, Warranty & Display')
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Show on Website')
                            ->default(true),
                        Toggle::make('is_featured')
                            ->label('Featured / Hot Badge'),
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
                        TextEntry::make('disabled_reason')
                            ->label('Auto-disabled alert')
                            ->color('danger')
                            ->visible(fn (?Product $record): bool => filled($record?->disabled_reason))
                            ->columnSpanFull(),
                        Textarea::make('warranty')
                            ->label('Warranty Terms')
                            ->rows(2)
                            ->columnSpanFull(),
                        RichEditor::make('description')
                            ->label('Full Description')
                            ->columnSpanFull(),
                    ]),

                Section::make('SEO & Google Search (Optional: Auto-Generated if Left Blank)')
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

    protected static function liveCost(?Product $record): ?float
    {
        if (! $record) {
            return null;
        }

        if ($record->cost_price !== null) {
            return (float) $record->cost_price;
        }

        $cheapest = app(PriceCalculator::class)->cheapestCost($record);

        return $cheapest !== null ? round($cheapest, 2) : null;
    }

    protected static function formatSupplierCost(?Product $record): string
    {
        $costPkr = self::liveCost($record);
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
            self::liveCost($record),
            self::mode($get),
            $get('fixed_price'),
            $get('markup_value'),
        );
    }
}
