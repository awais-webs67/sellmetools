<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Product;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Customer / Reseller Profile & Login Credentials')
                    ->description('Create or manage a customer or VIP reseller account. Phone numbers are automatically normalized into exact 923XXXXXXXXX WhatsApp format.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Full Name')
                            ->required()
                            ->maxLength(120)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if ($get('is_reseller') && blank($get('reseller_slug'))) {
                                    $set('reseller_slug', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('email')
                            ->label('Login Email')
                            ->email()
                            ->required()
                            ->maxLength(190)
                            ->unique(ignoreRecord: true),
                        TextInput::make('phone')
                            ->label('WhatsApp Number (Exact Format: 923XXXXXXXXX)')
                            ->placeholder('923001234567')
                            ->helperText('Enter 03001234567 or 923001234567, auto-converted to 923001234567 on save.')
                            ->maxLength(32)
                            ->dehydrateStateUsing(fn (?string $state) => filled($state) ? format_whatsapp($state) : null),
                        TextInput::make('password')
                            ->label('Login Password')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->helperText(fn (string $operation): string => $operation === 'edit' ? 'Leave blank to keep current password.' : 'Set login password for this user / reseller.'),
                        Toggle::make('is_active')
                            ->label('Account Active (Uncheck to Block User)')
                            ->default(true),
                        Select::make('admin_role')
                            ->label('Admin / Staff Role & Permissions')
                            ->options(User::adminRoleOptions())
                            ->default('')
                            ->selectablePlaceholder(false)
                            ->live()
                            ->afterStateHydrated(function (Select $component, ?User $record, ?string $state): void {
                                if ($record && $record->is_admin && blank($state)) {
                                    $component->state(User::ROLE_SUPER_ADMIN);
                                }
                            })
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                $set('is_admin', in_array($state, [User::ROLE_SUPER_ADMIN, User::ROLE_SALES, User::ROLE_SEO], true));
                            })
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? $state : null)
                            ->helperText('Choose Sales Staff for order/stock handling, SEO Specialist for SEO/content only, or Super Admin for full owner access.'),
                        Toggle::make('is_admin')
                            ->hidden()
                            ->dehydrated(true)
                            ->default(false),
                    ]),

                Section::make('VIP Reseller Panel Configuration (Secret Custom URL & Wholesale Pricing)')
                    ->description('Enable this to turn the user into a VIP Reseller with their own secret portal URL (e.g. mydomain.com/ali), global percentage discount, and per-product custom prices.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('is_reseller')
                            ->label('Enable VIP Reseller Portal')
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('reseller_slug')
                            ->label('Reseller Username / URL Slug')
                            ->prefix(rtrim(url('/'), '/').'/')
                            ->placeholder('ali')
                            ->alphaDash()
                            ->maxLength(80)
                            ->unique(ignoreRecord: true)
                            ->helperText('Opens secret reseller portal at e.g. '.url('/ali'))
                            ->visible(fn (Get $get): bool => (bool) $get('is_reseller'))
                            ->required(fn (Get $get): bool => (bool) $get('is_reseller')),
                        TextInput::make('reseller_discount_percent')
                            ->label('Global Discount Percentage (All Products)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(95)
                            ->default(0)
                            ->suffix('% OFF')
                            ->helperText('Applied automatically to all products unless overridden below for a specific product.')
                            ->visible(fn (Get $get): bool => (bool) $get('is_reseller')),
                        Textarea::make('reseller_notes')
                            ->label('Internal Reseller Notes')
                            ->rows(2)
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => (bool) $get('is_reseller')),

                        Repeater::make('resellerProductPrices')
                            ->relationship()
                            ->label('Individual Product Custom Prices / Percentages (Optional Overrides)')
                            ->helperText('Set either a fixed Custom Price (PKR) OR an individual Discount % for specific products.')
                            ->columns(3)
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => (bool) $get('is_reseller'))
                            ->defaultItems(0)
                            ->addActionLabel('+ Add Custom Product Price for this Reseller')
                            ->schema([
                                Select::make('product_id')
                                    ->label('Product')
                                    ->options(fn () => Product::query()->orderBy('name')->get()->mapWithKeys(fn (Product $p) => [
                                        $p->id => "{$p->name} (Retail: Rs ".number_format((float) $p->price).')',
                                    ]))
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                TextInput::make('custom_price')
                                    ->label('Fixed Custom Price (PKR)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->prefix('Rs')
                                    ->placeholder('Leave blank to use %'),
                                TextInput::make('discount_percent')
                                    ->label('Or Product Discount %')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(95)
                                    ->suffix('% OFF')
                                    ->placeholder('e.g. 20'),
                            ]),
                    ]),
            ]);
    }
}
