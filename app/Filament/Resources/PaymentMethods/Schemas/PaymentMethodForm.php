<?php

namespace App\Filament\Resources\PaymentMethods\Schemas;

use App\Filament\Support\GalleryPicker;
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
            ->columns(1)
            ->components([
                Section::make('Payment Account Details')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label('Method Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->label('Unique Code')
                            ->required()
                            ->maxLength(30)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->helperText('e.g. easypaisa, jazzcash, binance'),
                        Select::make('type')
                            ->options([
                                'manual' => 'Manual (Verify TID)',
                                'auto' => 'Automatic',
                            ])
                            ->default('manual')
                            ->required(),
                        Select::make('currency')
                            ->options([
                                'PKR' => 'PKR (Rs)',
                                'USDT' => 'USDT ($)',
                            ])
                            ->default('PKR')
                            ->required(),
                        TextInput::make('account_title')
                            ->label('Account Holder Name')
                            ->maxLength(255),
                        TextInput::make('account_number')
                            ->label('Account Number / Binance Pay ID')
                            ->maxLength(255),
                        GalleryPicker::make('logo', 'Method Logo', 'gallery'),
                        Textarea::make('instructions')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Shown to customer on the payment screen.'),
                        Toggle::make('requires_sender_number')
                            ->label('Ask customer for sender phone number')
                            ->default(true),
                        Toggle::make('is_active')
                            ->label('Active on checkout')
                            ->default(true),
                        TextInput::make('sort')
                            ->label('Sort Order')
                            ->rule('integer')
                            ->default(0)
                            ->required(),
                    ]),
            ]);
    }
}
