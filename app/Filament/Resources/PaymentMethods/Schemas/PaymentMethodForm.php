<?php

namespace App\Filament\Resources\PaymentMethods\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentMethodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Method')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->required()
                            ->maxLength(30)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->helperText('Unique identifier, e.g. easypaisa, jazzcash, binance.'),
                        Select::make('type')
                            ->options([
                                'manual' => 'Manual (admin verifies TID)',
                                'auto' => 'Automatic',
                            ])
                            ->default('manual')
                            ->required()
                            ->native(false),
                        Select::make('currency')
                            ->options([
                                'PKR' => 'PKR',
                                'USDT' => 'USDT',
                            ])
                            ->default('PKR')
                            ->required()
                            ->native(false)
                            ->helperText('USDT methods show the order total converted at the current rate.'),
                        TextInput::make('account_title')
                            ->maxLength(255),
                        TextInput::make('account_number')
                            ->label('Account number / Pay ID')
                            ->maxLength(255),
                        Textarea::make('instructions')
                            ->rows(4)
                            ->columnSpanFull()
                            ->helperText('Shown to the customer on the payment page.'),
                    ]),
                Section::make('Display')
                    ->columnSpan(1)
                    ->schema([
                        FileUpload::make('logo')
                            ->image()
                            ->disk('public')
                            ->directory('payment-methods')
                            ->visibility('public')
                            ->maxSize(1024),
                        Toggle::make('requires_sender_number')
                            ->label('Require sender number')
                            ->default(true),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        TextInput::make('sort')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                    ]),
            ]);
    }
}
