<?php

namespace App\Filament\Resources\Vouchers\Schemas;

use App\Models\Product;
use App\Models\Voucher;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class VoucherForm
{
    public static function configure(Schema $schema): Schema
    {
        Voucher::ensureSchemaExists();

        return $schema
            ->components([
                Section::make('Voucher Code & Discount Rules')
                    ->description('Generate a promo code, set the percentage or fixed PKR discount, and choose whether it applies storewide (Allover) or to specific products.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('code')
                            ->label('Voucher / Promo Code')
                            ->required()
                            ->maxLength(64)
                            ->default(fn (): string => Voucher::generateUniqueCode())
                            ->unique(ignoreRecord: true)
                            ->placeholder('e.g. SAVE20 or SMT-8F4K2A')
                            ->dehydrateStateUsing(fn (?string $state): string => strtoupper(trim((string) $state)))
                            ->suffixAction(
                                Action::make('generateCode')
                                    ->label('Generate')
                                    ->icon(Heroicon::OutlinedSparkles)
                                    ->action(fn (Set $set) => $set('code', Voucher::generateUniqueCode()))
                            )
                            ->helperText('Customers enter this code at checkout. Click the sparkle icon to generate a new random code.'),

                        TextInput::make('title')
                            ->label('Campaign Title / Note (Optional)')
                            ->maxLength(255)
                            ->placeholder('e.g. Weekend Flash Sale 20% Off / VIP Promo')
                            ->helperText('Internal label or short promo description.'),

                        Select::make('discount_type')
                            ->label('Discount Type')
                            ->options([
                                'percentage' => 'Percentage Discount (%)',
                                'fixed' => 'Fixed Amount (PKR)',
                            ])
                            ->default('percentage')
                            ->required()
                            ->live(),

                        TextInput::make('discount_value')
                            ->label(fn (Get $get): string => $get('discount_type') === 'fixed'
                                ? 'Discount Amount (PKR)'
                                : 'Discount Percentage (%)')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->maxValue(fn (Get $get): ?int => $get('discount_type') === 'fixed' ? null : 100)
                            ->suffix(fn (Get $get): string => $get('discount_type') === 'fixed' ? 'PKR' : '%')
                            ->placeholder(fn (Get $get): string => $get('discount_type') === 'fixed' ? 'e.g. 500' : 'e.g. 20'),

                        Select::make('scope')
                            ->label('Product Scope (Where Can This Voucher Be Used?)')
                            ->options([
                                'all' => 'All Products (Allover / Entire Store)',
                                'specific' => 'Specific Product(s) Only',
                            ])
                            ->default('all')
                            ->required()
                            ->live()
                            ->columnSpanFull(),

                        Select::make('product_ids')
                            ->label('Select Eligible Product(s)')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->visible(fn (Get $get): bool => $get('scope') === 'specific')
                            ->required(fn (Get $get): bool => $get('scope') === 'specific')
                            ->helperText('Pick one or multiple products that this voucher is allowed to discount.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Usability Limits & Schedule')
                    ->description('Control how many times this voucher can be redeemed overall, per customer, and optional validity dates.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('max_uses')
                            ->label('Total Usability Limit (Max Total Uses)')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('Unlimited (leave blank)')
                            ->helperText('Total number of orders allowed to use this voucher across the entire store.'),

                        TextInput::make('max_uses_per_customer')
                            ->label('Per-Customer Limit (Max Uses Per Email)')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('Unlimited per customer (leave blank)')
                            ->helperText('Set to 1 so each customer can only redeem this voucher once.'),

                        TextInput::make('min_order_amount')
                            ->label('Minimum Order Subtotal (PKR, Optional)')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('PKR')
                            ->placeholder('No minimum')
                            ->helperText('Require the order subtotal to be at least this amount.'),

                        TextInput::make('max_discount_amount')
                            ->label('Maximum Discount Cap (PKR, Optional)')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('PKR')
                            ->placeholder('No cap')
                            ->visible(fn (Get $get): bool => $get('discount_type') !== 'fixed')
                            ->helperText('Optional maximum PKR discount limit for percentage vouchers.'),

                        DateTimePicker::make('starts_at')
                            ->label('Valid From (Optional)')
                            ->native(false)
                            ->placeholder('Starts immediately'),

                        DateTimePicker::make('expires_at')
                            ->label('Expires At (Optional)')
                            ->native(false)
                            ->placeholder('Never expires'),

                        Toggle::make('is_active')
                            ->label('Active (Enable Voucher for Checkout)')
                            ->default(true)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
