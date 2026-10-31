<?php

namespace App\Filament\Pages;

use App\Filament\Support\Listings;
use App\Models\Setting;
use App\Services\TelegramNotifier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Key/value store settings backed by the `settings` table.
 *
 * @property-read Schema $form
 */
class StoreSettings extends Page
{
    /** Keys managed by this page (all live in Setting::DEFAULTS). */
    protected const KEYS = [
        'store_name', 'store_tagline', 'announcement', 'currency_symbol',
        'usdt_rate', 'price_rounding', 'order_expiry_minutes',
        'telegram_bot_token', 'telegram_admin_chat_id',
        'support_whatsapp', 'support_telegram', 'support_email',
    ];

    /** Changing these re-prices every supplier-sourced product. */
    protected const PRICING_KEYS = ['usdt_rate', 'price_rounding'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Store Settings';

    protected static ?string $title = 'Store Settings';

    protected static ?string $slug = 'settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(array_intersect_key(Setting::values(), array_flip(self::KEYS)));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(2)
            ->components([
                Section::make('General')
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->columns(2)
                    ->schema([
                        TextInput::make('store_name')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('currency_symbol')
                            ->required()
                            ->maxLength(10),
                        TextInput::make('store_tagline')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('announcement')
                            ->helperText('Optional banner shown across the storefront. Leave empty to hide.')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('Pricing & orders')
                    ->icon(Heroicon::OutlinedCurrencyDollar)
                    ->columns(3)
                    ->schema([
                        TextInput::make('usdt_rate')
                            ->label('USDT → PKR rate')
                            ->numeric()
                            ->minValue(0.0001)
                            ->required()
                            ->prefix('1 USDT =')
                            ->suffix('PKR'),
                        TextInput::make('price_rounding')
                            ->label('Round prices up to')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->suffix('PKR')
                            ->helperText('0 = no rounding'),
                        TextInput::make('order_expiry_minutes')
                            ->label('Payment window')
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->suffix('min'),
                    ])
                    ->description('Changing the rate or rounding re-prices every supplier-sourced product on save.'),
                Section::make('Telegram alerts')
                    ->icon(Heroicon::OutlinedBellAlert)
                    ->description('Admin notifications for new payments, failed deliveries and low supplier balances.')
                    ->schema([
                        TextInput::make('telegram_bot_token')
                            ->label('Bot token')
                            ->password()
                            ->revealable()
                            ->autocomplete('off'),
                        TextInput::make('telegram_admin_chat_id')
                            ->label('Admin chat ID'),
                        Actions::make([
                            Action::make('testTelegram')
                                ->label('Send test message')
                                ->icon(Heroicon::OutlinedPaperAirplane)
                                ->color('gray')
                                ->action(fn () => $this->sendTelegramTest()),
                        ]),
                    ]),
                Section::make('Support contacts')
                    ->icon(Heroicon::OutlinedLifebuoy)
                    ->schema([
                        TextInput::make('support_whatsapp')
                            ->label('WhatsApp number')
                            ->tel()
                            ->placeholder('+923001234567'),
                        TextInput::make('support_telegram')
                            ->label('Telegram username / link')
                            ->placeholder('@sellmetools'),
                        TextInput::make('support_email')
                            ->label('Support email')
                            ->email(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Save settings')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $before = Setting::values();

        Setting::setMany(collect(self::KEYS)
            ->mapWithKeys(fn (string $key): array => [$key => isset($data[$key]) ? trim((string) $data[$key]) : null])
            ->all());

        $pricingChanged = collect(self::PRICING_KEYS)
            ->contains(fn (string $key): bool => (float) ($before[$key] ?? 0) !== (float) ($data[$key] ?? 0));

        $notification = Notification::make()->title('Settings saved')->success();

        if ($pricingChanged) {
            $count = Listings::refreshAllSourced();
            $notification->body("{$count} supplier-sourced product(s) re-priced.");
        }

        $notification->send();
    }

    protected function sendTelegramTest(): void
    {
        $token = trim((string) ($this->data['telegram_bot_token'] ?? ''));
        $chatId = trim((string) ($this->data['telegram_admin_chat_id'] ?? ''));

        if ($token === '' || $chatId === '') {
            Notification::make()->title('Enter the bot token and chat ID first')->warning()->send();

            return;
        }

        $storeName = TelegramNotifier::e((string) ($this->data['store_name'] ?? 'SellMeTools'));
        $sent = app(TelegramNotifier::class)->send($token, $chatId, "✅ <b>{$storeName}</b>\nTelegram alerts are working.");

        $sent
            ? Notification::make()->title('Test message sent')->success()->send()
            : Notification::make()->title('Telegram rejected the message')->body('Check the bot token, chat ID and that you have started the bot.')->danger()->send();
    }
}
