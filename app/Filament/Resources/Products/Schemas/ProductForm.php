<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\PricingMode;
use App\Filament\Support\Money;
use App\Models\Product;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Product')
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Details')
                            ->schema([
                                Grid::make(2)->schema([
                                    TextInput::make('name')
                                        ->required()
                                        ->maxLength(255),
                                    TextInput::make('slug')
                                        ->maxLength(255)
                                        ->alphaDash()
                                        ->unique(ignoreRecord: true)
                                        ->helperText('Leave blank to generate from the name.'),
                                    Select::make('category_id')
                                        ->label('Category')
                                        ->relationship('category', 'name')
                                        ->searchable()
                                        ->preload(),
                                    TextInput::make('short_description')
                                        ->maxLength(500),
                                ]),
                                FileUpload::make('image')
                                    ->image()
                                    ->disk('public')
                                    ->directory('products')
                                    ->visibility('public')
                                    ->imageEditor()
                                    ->maxSize(4096),
                                RichEditor::make('description')
                                    ->columnSpanFull(),
                                Textarea::make('warranty')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ]),
                        Tab::make('Pricing')
                            ->schema([
                                Grid::make(2)->schema([
                                    Select::make('pricing_mode')
                                        ->options(PricingMode::options())
                                        ->default(PricingMode::Fixed->value)
                                        ->required()
                                        ->live()
                                        ->helperText('Markup modes apply to the cheapest active supplier source. Products without a supplier source always use the fixed price.'),
                                    TextInput::make('fixed_price')
                                        ->label('Fixed price (PKR)')
                                        ->numeric()
                                        ->minValue(0)
                                        ->prefix('Rs')
                                        ->required(fn (Get $get): bool => self::mode($get) === PricingMode::Fixed)
                                        ->helperText(fn (Get $get): ?string => self::mode($get) === PricingMode::Fixed
                                            ? null
                                            : 'Fallback price used when no supplier source is available.'),
                                    TextInput::make('markup_value')
                                        ->label(fn (Get $get): string => self::mode($get) === PricingMode::MarkupPercent ? 'Markup (%)' : 'Markup (PKR)')
                                        ->numeric()
                                        ->minValue(0)
                                        ->default(0)
                                        ->visible(fn (Get $get): bool => self::mode($get) !== PricingMode::Fixed),
                                    TextInput::make('compare_at_price')
                                        ->label('Compare-at price (PKR)')
                                        ->numeric()
                                        ->minValue(0)
                                        ->prefix('Rs')
                                        ->helperText('Optional "was" price shown struck-through.'),
                                ]),
                                Section::make('Current values')
                                    ->description('Recalculated automatically after saving and on every supplier sync.')
                                    ->columns(4)
                                    ->visibleOn('edit')
                                    ->schema([
                                        TextEntry::make('cost_price')
                                            ->label('Cost (cheapest source)')
                                            ->state(fn (?Product $record): string => Money::pkr($record?->cost_price)),
                                        TextEntry::make('price')
                                            ->label('Selling price')
                                            ->weight('bold')
                                            ->color('primary')
                                            ->state(fn (?Product $record): string => Money::pkr($record?->price)),
                                        TextEntry::make('profit')
                                            ->state(fn (?Product $record): string => $record?->cost_price === null
                                                ? '—'
                                                : Money::pkr((float) $record->price - (float) $record->cost_price)),
                                        TextEntry::make('stock')
                                            ->state(fn (?Product $record): string => $record?->stock_unlimited ? '∞' : (string) ($record?->available_stock ?? 0)),
                                    ]),
                            ]),
                        Tab::make('Visibility')
                            ->schema([
                                Grid::make(2)->schema([
                                    Toggle::make('is_active')
                                        ->label('Active on storefront')
                                        ->default(true),
                                    Toggle::make('is_featured')
                                        ->label('Featured'),
                                    TextInput::make('max_per_order')
                                        ->numeric()
                                        ->minValue(1)
                                        ->default(5)
                                        ->required(),
                                    TextInput::make('sort')
                                        ->numeric()
                                        ->minValue(0)
                                        ->default(0)
                                        ->required(),
                                    TextEntry::make('disabled_reason')
                                        ->label('Auto-disabled')
                                        ->color('danger')
                                        ->visible(fn (?Product $record): bool => filled($record?->disabled_reason))
                                        ->columnSpanFull(),
                                ]),
                            ]),
                    ]),
            ]);
    }

    protected static function mode(Get $get): PricingMode
    {
        $mode = $get('pricing_mode');

        return $mode instanceof PricingMode ? $mode : (PricingMode::tryFrom((string) $mode) ?? PricingMode::Fixed);
    }
}
