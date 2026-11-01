<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\PricingMode;
use App\Filament\Support\GalleryPicker;
use App\Filament\Support\Money;
use App\Models\Product;
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
                            ->preload(),
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
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                    ->schema([
                        Select::make('pricing_mode')
                            ->label('Pricing Rule')
                            ->options(PricingMode::options())
                            ->default(PricingMode::Fixed->value)
                            ->required()
                            ->live()
                            ->columnSpan(['default' => 1, 'sm' => 2]),
                        TextInput::make('fixed_price')
                            ->label('Fixed Selling Price (PKR)')
                            ->placeholder('550')
                            ->rule('numeric')
                            ->rule('min:0')
                            ->required(fn (Get $get): bool => self::mode($get) === PricingMode::Fixed),
                        TextInput::make('markup_value')
                            ->label(fn (Get $get): string => self::mode($get) === PricingMode::MarkupPercent ? 'Profit Markup (%)' : 'Profit Markup (PKR)')
                            ->placeholder('250')
                            ->rule('numeric')
                            ->rule('min:0')
                            ->default(0)
                            ->visible(fn (Get $get): bool => self::mode($get) !== PricingMode::Fixed),
                        TextInput::make('compare_at_price')
                            ->label('Old Price / Discount Strike (PKR)')
                            ->placeholder('800')
                            ->rule('numeric')
                            ->rule('min:0'),
                        Grid::make(['default' => 2, 'sm' => 4])
                            ->columnSpanFull()
                            ->visibleOn('edit')
                            ->schema([
                                TextEntry::make('cost_price')
                                    ->label('Supplier Cost')
                                    ->state(fn (?Product $record): string => Money::pkr($record?->cost_price)),
                                TextEntry::make('price')
                                    ->label('Final Selling Price')
                                    ->weight('bold')
                                    ->color('primary')
                                    ->state(fn (?Product $record): string => Money::pkr($record?->price)),
                                TextEntry::make('profit')
                                    ->label('Your Profit')
                                    ->state(fn (?Product $record): string => $record?->cost_price === null
                                        ? '—'
                                        : Money::pkr((float) $record->price - (float) $record->cost_price)),
                                TextEntry::make('stock')
                                    ->label('Live Stock')
                                    ->state(fn (?Product $record): string => $record?->stock_unlimited ? '∞' : (string) ($record?->available_stock ?? 0)),
                            ]),
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

                Section::make('SEO & Google Search (Optional — Auto-Generated if Left Blank)')
                    ->collapsed()
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('meta_title')
                            ->label('Custom SEO Meta Title')
                            ->placeholder('Buy ChatGPT Plus in Pakistan – Instant Delivery')
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
}
