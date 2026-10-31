<?php

namespace App\Filament\Resources\PaymentMethods\Schemas;

use App\Filament\Support\GalleryPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PaymentMethodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Gateway Status & Basic Info')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Enable this Payment Method on Checkout')
                            ->default(true)
                            ->columnSpanFull(),
                        TextInput::make('name')
                            ->label('Method Display Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->label('Unique Code')
                            ->required()
                            ->maxLength(30)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->helperText('e.g. easypaisa, jazzcash, binance, payfast, whatsapp_manual'),
                        Select::make('type')
                            ->label('Verification Mode')
                            ->options([
                                'manual' => 'Manual / Semi-Auto (TID + WhatsApp + SMS Auto-Match)',
                                'auto' => 'Online Auto Gateway / API',
                            ])
                            ->default('manual')
                            ->live()
                            ->required(),
                        Select::make('currency')
                            ->options([
                                'PKR' => 'PKR (Rs)',
                                'USDT' => 'USDT ($)',
                            ])
                            ->default('PKR')
                            ->required(),
                        TextInput::make('account_title')
                            ->label('Account Holder Name / Network')
                            ->placeholder('e.g. Muhammad Awais / TRC20')
                            ->maxLength(255),
                        TextInput::make('account_number')
                            ->label('Account Number / IBAN / Wallet / Binance Pay ID')
                            ->placeholder('e.g. 03001234567')
                            ->maxLength(255),
                        GalleryPicker::make('logo', 'Method Logo', 'gallery'),
                        Textarea::make('instructions')
                            ->label('Customer Instructions')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Shown to customer on the payment screen.'),
                        Toggle::make('requires_sender_number')
                            ->label('Ask customer for sender phone number')
                            ->default(true),
                        TextInput::make('sort')
                            ->label('Sort Order')
                            ->rule('integer')
                            ->default(0)
                            ->required(),
                    ]),

                Section::make('Auto-Gateway & WhatsApp Settings (Optional)')
                    ->description('Configure automatic API credentials or custom WhatsApp number for this gateway.')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('meta.whatsapp_number')
                            ->label('WhatsApp Number for this Method (Optional)')
                            ->placeholder('Uses global Support WhatsApp if left blank (e.g. 923001234567)'),
                        Select::make('meta.gateway')
                            ->label('Auto Gateway Driver')
                            ->options([
                                'sms_auto' => 'SMS / Notification Forwarder Auto-Match (Easypaisa/JazzCash/NayaPay/SadaPay)',
                                'binance' => 'Binance Pay API Auto-Verify',
                                'custom_link' => 'External Payment Link / Gateway Redirect (PayFast, Paymob, Cryptomus)',
                            ])
                            ->placeholder('Standard TID + SMS Auto-Match')
                            ->live(),
                        TextInput::make('meta.binance_api_key')
                            ->label('Binance Read-Only API Key')
                            ->password()
                            ->revealable()
                            ->visible(fn (Get $get): bool => $get('meta.gateway') === 'binance' || $get('code') === 'binance'),
                        TextInput::make('meta.binance_api_secret')
                            ->label('Binance API Secret')
                            ->password()
                            ->revealable()
                            ->visible(fn (Get $get): bool => $get('meta.gateway') === 'binance' || $get('code') === 'binance'),
                        TextInput::make('meta.checkout_url')
                            ->label('Gateway Payment / Checkout URL')
                            ->placeholder('https://your-gateway-link.com/pay?order={order}&amount={amount}')
                            ->helperText('You can use {order}, {amount}, and {currency} placeholders.')
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $get('meta.gateway') === 'custom_link' || $get('type') === 'auto'),
                        TextInput::make('meta.merchant_id')
                            ->label('Merchant ID / App ID')
                            ->visible(fn (Get $get): bool => $get('type') === 'auto'),
                        TextInput::make('meta.merchant_secret')
                            ->label('Merchant Secret / API Key')
                            ->password()
                            ->revealable()
                            ->visible(fn (Get $get): bool => $get('type') === 'auto'),
                    ]),
            ]);
    }
}
