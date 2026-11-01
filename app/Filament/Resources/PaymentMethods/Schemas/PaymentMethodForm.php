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

                Section::make('Wallet Auto-Verification & Gateway Linking')
                    ->description('Link this wallet (Easypaisa, JazzCash, NayaPay, SadaPay, or Binance) to Auto-SMS, Binance Read-Only API, or a Merchant Checkout Link.')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        Select::make('meta.gateway')
                            ->label('How to Auto-Verify this Wallet')
                            ->options([
                                'sms_auto' => 'Auto-SMS / Notification Match + Manual TID (Easypaisa, JazzCash, NayaPay, SadaPay)',
                                'binance' => 'Binance Auto-Verify API (Binance Pay Order ID + On-Chain USDT TxID)',
                                'custom_link' => 'Link Wallet via Merchant Gateway (PayFast / Paymob / Custom URL)',
                            ])
                            ->default('sms_auto')
                            ->live(),
                        TextInput::make('meta.whatsapp_number')
                            ->label('WhatsApp Number for Receipt Template (Exact Format: 923XXXXXXXXX)')
                            ->placeholder('923001234567 (Uses Store Support WhatsApp if blank)')
                            ->dehydrateStateUsing(fn (?string $state) => filled($state) ? format_whatsapp($state) : null),
                        TextInput::make('meta.binance_api_key')
                            ->label('Binance Read-Only API Key (From Binance -> API Management)')
                            ->password()
                            ->revealable()
                            ->helperText('Create a normal API Key in your Binance account with ONLY "Enable Reading" checked. System will auto-verify Binance Pay Order IDs & USDT TxIDs in 1 second!')
                            ->visible(fn (Get $get): bool => $get('meta.gateway') === 'binance' || in_array($get('code'), ['binance', 'usdt_trc20'], true)),
                        TextInput::make('meta.binance_api_secret')
                            ->label('Binance Read-Only API Secret')
                            ->password()
                            ->revealable()
                            ->visible(fn (Get $get): bool => $get('meta.gateway') === 'binance' || in_array($get('code'), ['binance', 'usdt_trc20'], true)),
                        TextInput::make('meta.checkout_url')
                            ->label('Linked Merchant Gateway URL (PayFast / Paymob / Wallet Link)')
                            ->placeholder('https://your-gateway-link.com/pay?order={order}&amount={amount}')
                            ->helperText('Supports {order}, {amount}, and {currency} placeholders.')
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $get('meta.gateway') === 'custom_link' || $get('type') === 'auto'),
                        TextInput::make('meta.merchant_id')
                            ->label('Merchant ID (If using PayFast / Paymob)')
                            ->visible(fn (Get $get): bool => $get('meta.gateway') === 'custom_link' || $get('type') === 'auto'),
                        TextInput::make('meta.merchant_secret')
                            ->label('Merchant Secret Key')
                            ->password()
                            ->revealable()
                            ->visible(fn (Get $get): bool => $get('meta.gateway') === 'custom_link' || $get('type') === 'auto'),
                    ]),
            ]);
    }
}
