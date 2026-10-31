<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Services\Suppliers\SupplierManager;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Connection')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('driver')
                            ->label('API format')
                            ->options(SupplierManager::driverOptions())
                            ->default(array_key_first(SupplierManager::driverOptions()))
                            ->required()
                            ->native(false),
                        TextInput::make('base_url')
                            ->label('Base URL')
                            ->url()
                            ->required()
                            ->maxLength(255)
                            ->placeholder('https://supplier.example.com/api'),
                        TextInput::make('api_key')
                            ->label('API key')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): ?string => $operation === 'edit'
                                ? 'Stored encrypted. Leave blank to keep the current key.'
                                : 'Stored encrypted.'),
                    ]),
                Section::make('Settings')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('currency')
                            ->required()
                            ->default('USDT')
                            ->maxLength(10)
                            ->helperText('Currency of supplier prices & balance. Non-PKR amounts use the USDT rate.'),
                        TextInput::make('priority')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->default(100)
                            ->helperText('Lower = preferred when two suppliers have the same cost.'),
                        TextInput::make('low_balance_threshold')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->default(5)
                            ->helperText('Telegram alert when the balance drops below this.'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }
}
