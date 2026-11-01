<?php

namespace App\Filament\Pages;

use App\Filament\Support\GalleryPicker;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use UnitEnum;

/**
 * Key/value store settings backed by the `settings` table.
 * Each section has its own independent Save button so the admin can save any section
 * separately without filling unrelated optional fields.
 *
 * @property-read Schema $form
 */
class StoreSettings extends Page
{
    /** Keys managed by this page (all live in Setting::DEFAULTS). */
    protected const KEYS = [
        'store_name', 'store_tagline', 'store_logo', 'announcement', 'currency_symbol',
        'usdt_rate', 'price_rounding', 'order_expiry_minutes',
        'sms_webhook_secret', 'admin_quick_pin',
        'greenapi_instance_id', 'greenapi_api_token', 'ntfy_topic',
        'whatsapp_callmebot_apikey', 'whatsapp_admin_webhook_url',
        'telegram_bot_token', 'telegram_admin_chat_id',
        'support_whatsapp', 'support_telegram', 'support_email',
        'seo_title', 'seo_description', 'seo_keywords', 'google_site_verification', 'google_analytics_id',
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncServerUpdate')
                ->label('🔄 Update Site, Migrate DB & Clear Cache (No Terminal Needed)')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('primary')
                ->action(function (): void {
                    $gitOutput = '';
                    try {
                        if (function_exists('shell_exec') && is_dir(base_path('.git'))) {
                            $gitOutput = trim((string) @shell_exec('cd '.escapeshellarg(base_path()).' && git pull origin main 2>&1'));
                        }
                    } catch (\Throwable) {
                        // Ignore if shell_exec is disabled on shared hosting
                    }

                    try {
                        Artisan::call('migrate', ['--force' => true]);
                        Artisan::call('view:clear');
                        Artisan::call('cache:clear');
                        Artisan::call('route:clear');
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Migration / Cache Error')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('✅ Database Migrated & Cache Cleared!')
                        ->body($gitOutput !== '' ? "Git: {$gitOutput}" : 'All new database tables/columns and views are now live.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(1)
            ->components([
                Section::make('Telegram Admin Bot Alerts (With 1-Tap Approve & Decline Buttons)')
                    ->icon(Heroicon::OutlinedBellAlert)
                    ->description('Step 1: Paste your Bot Token from @BotFather. Step 2: Open your bot in Telegram and press START. Step 3: Click "Auto-Detect Chat ID, Save & Send Test" below!')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('telegram_bot_token')
                            ->label('Telegram Bot Token')
                            ->placeholder('123456789:AAH...')
                            ->password()
                            ->revealable()
                            ->autocomplete('off'),
                        TextInput::make('telegram_admin_chat_id')
                            ->label('Admin Numeric Chat ID (Leave blank to Auto-Detect!)')
                            ->placeholder('e.g. 582910384 (or click Auto-Detect below)'),
                        Actions::make([
                            Action::make('saveTelegramSection')
                                ->label('Save Telegram Bot Only')
                                ->icon(Heroicon::OutlinedCheck)
                                ->color('gray')
                                ->action(fn () => $this->saveKeys(['telegram_bot_token', 'telegram_admin_chat_id'], 'Telegram Bot settings saved')),
                            Action::make('testTelegram')
                                ->label('Auto-Detect Chat ID, Save & Send Test Message')
                                ->icon(Heroicon::OutlinedPaperAirplane)
                                ->color('primary')
                                ->action(fn () => $this->sendTelegramTest()),
                        ])->columnSpanFull(),
                    ]),

                Section::make('1-Tap WhatsApp & Free Instant Mobile Push (No CallMeBot Limits!)')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->description('Set your Admin WhatsApp Number in exact format (923XXXXXXXXX) so customer WhatsApp verification buttons always open your direct chat.')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('support_whatsapp')
                            ->label('Admin WhatsApp Number (Exact Format: 923XXXXXXXXX)')
                            ->placeholder('923001234567')
                            ->helperText('Enter 03001234567 or 923001234567 — auto-converted to 923001234567. Customer WhatsApp buttons go directly to this number!'),
                        TextInput::make('admin_quick_pin')
                            ->label('Admin 1-Tap WhatsApp PIN')
                            ->placeholder('7860')
                            ->maxLength(20)
                            ->helperText('Default: 7860. Protects 1-tap WhatsApp links on your phone.'),
                        TextInput::make('ntfy_topic')
                            ->label('ntfy.sh Free Mobile Push Topic (Zero Signup!)')
                            ->placeholder('e.g. sellmetools_awais_orders_786')
                            ->helperText('Install free "ntfy" app on Android/iOS -> Subscribe to this exact topic -> Get instant phone alerts with 1-Tap Approve/Decline buttons!'),
                        TextInput::make('greenapi_instance_id')
                            ->label('Green-API Free WhatsApp Instance ID (green-api.com)')
                            ->placeholder('e.g. 7103948576')
                            ->helperText('100% Free Forever Developer Plan at green-api.com (no slot limits!). Sends auto WhatsApp to Admin & Customer.'),
                        TextInput::make('greenapi_api_token')
                            ->label('Green-API Token (apiTokenInstance)')
                            ->password()
                            ->revealable()
                            ->placeholder('Paste apiTokenInstance from green-api.com'),
                        Actions::make([
                            Action::make('saveWhatsappSection')
                                ->label('Save WhatsApp & Push Settings')
                                ->icon(Heroicon::OutlinedCheck)
                                ->color('primary')
                                ->action(fn () => $this->saveKeys([
                                    'support_whatsapp', 'admin_quick_pin', 'ntfy_topic', 'greenapi_instance_id', 'greenapi_api_token',
                                    'whatsapp_callmebot_apikey', 'whatsapp_admin_webhook_url',
                                ], 'WhatsApp & Push settings saved')),
                            Action::make('testNtfyPush')
                                ->label('Save & Send Test ntfy Notification')
                                ->icon(Heroicon::OutlinedBellAlert)
                                ->color('gray')
                                ->action(fn () => $this->sendNtfyTest()),
                        ])->columnSpanFull(),
                    ]),

                Section::make('Pricing & Dollar Rate')
                    ->icon(Heroicon::OutlinedCurrencyDollar)
                    ->description('Set the USDT → PKR conversion rate. Saving automatically recalculates prices for all supplier products.')
                    ->columns(['default' => 1, 'sm' => 3])
                    ->schema([
                        TextInput::make('usdt_rate')
                            ->label('1 USDT in PKR (Dollar Rate)')
                            ->placeholder('295')
                            ->rule('numeric')
                            ->rule('min:1')
                            ->helperText('Example: 295'),
                        TextInput::make('price_rounding')
                            ->label('Round Selling Price To (PKR)')
                            ->placeholder('10')
                            ->rule('numeric')
                            ->rule('min:0')
                            ->helperText('0 = exact price, 10 = round to nearest 10'),
                        TextInput::make('order_expiry_minutes')
                            ->label('Order Payment Time (Minutes)')
                            ->placeholder('15')
                            ->rule('integer')
                            ->rule('min:1')
                            ->helperText('Minutes stock stays reserved'),
                        Actions::make([
                            Action::make('savePricingSection')
                                ->label('Save Pricing & Recalculate Products')
                                ->icon(Heroicon::OutlinedCheck)
                                ->color('primary')
                                ->action(fn () => $this->saveKeys(['usdt_rate', 'price_rounding', 'order_expiry_minutes'], 'Pricing settings saved')),
                        ])->columnSpanFull(),
                    ]),

                Section::make('Store Details & Logo')
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('store_name')
                            ->label('Store Name')
                            ->maxLength(100),
                        TextInput::make('currency_symbol')
                            ->label('Currency Symbol')
                            ->maxLength(10),
                        TextInput::make('store_tagline')
                            ->label('Short Tagline')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        GalleryPicker::make('store_logo', 'Custom Store Logo (Optional)', 'gallery'),
                        Textarea::make('announcement')
                            ->label('Top Announcement Bar (Optional)')
                            ->helperText('Shown at the very top of the website. Leave empty to hide.')
                            ->rows(2)
                            ->columnSpanFull(),
                        Actions::make([
                            Action::make('saveStoreSection')
                                ->label('Save Store Details')
                                ->icon(Heroicon::OutlinedCheck)
                                ->color('primary')
                                ->action(fn () => $this->saveKeys(['store_name', 'currency_symbol', 'store_tagline', 'store_logo', 'announcement'], 'Store details saved')),
                        ])->columnSpanFull(),
                    ]),

                Section::make('Support Contacts (WhatsApp / Telegram / Email)')
                    ->icon(Heroicon::OutlinedLifebuoy)
                    ->columns(['default' => 1, 'sm' => 3])
                    ->schema([
                        TextInput::make('support_whatsapp')
                            ->label('WhatsApp Number')
                            ->placeholder('923001234567'),
                        TextInput::make('support_telegram')
                            ->label('Telegram Username')
                            ->placeholder('@sellmetools'),
                        TextInput::make('support_email')
                            ->label('Support Email'),
                        Actions::make([
                            Action::make('saveSupportSection')
                                ->label('Save Support Contacts')
                                ->icon(Heroicon::OutlinedCheck)
                                ->color('primary')
                                ->action(fn () => $this->saveKeys(['support_whatsapp', 'support_telegram', 'support_email'], 'Support contacts saved')),
                        ])->columnSpanFull(),
                    ]),

                Section::make('Auto-SMS Payment Gateway (Optional — Leave Blank if Not Using)')
                    ->icon(Heroicon::OutlinedBolt)
                    ->description('Optional: Forward payment SMS from your phone to '.url('/api/webhooks/payment-sms').' to auto-approve matching TIDs.')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('sms_webhook_secret')
                            ->label('SMS Webhook Secret Key (Optional)')
                            ->placeholder('smt_auto_pay_secret_2026')
                            ->helperText('Optional. Leave as default or empty if you do not use an SMS Forwarder app.'),
                        Actions::make([
                            Action::make('saveSmsSection')
                                ->label('Save SMS Webhook Key')
                                ->icon(Heroicon::OutlinedCheck)
                                ->color('gray')
                                ->action(fn () => $this->saveKeys(['sms_webhook_secret'], 'SMS webhook settings saved')),
                        ])->columnSpanFull(),
                    ]),

                Section::make('SEO & Google Search Ranking (Optional)')
                    ->icon(Heroicon::OutlinedMagnifyingGlassCircle)
                    ->description('Optimize your store for Google Search. Live XML Sitemap: '.url('/sitemap.xml').' | AI Index: '.url('/llms.txt'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('seo_title')
                            ->label('Homepage SEO Title')
                            ->placeholder('Buy ChatGPT Plus, Canva Pro & Subscriptions in Pakistan')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('seo_description')
                            ->label('Homepage Meta Description')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Textarea::make('seo_keywords')
                            ->label('Target SEO Keywords (Comma separated)')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                        TextInput::make('google_site_verification')
                            ->label('Google Search Console Verification Code (Optional)')
                            ->placeholder('Paste token only'),
                        TextInput::make('google_analytics_id')
                            ->label('Google Analytics 4 ID (Optional)')
                            ->placeholder('G-XXXXXXXXXX'),
                        Actions::make([
                            Action::make('saveSeoSection')
                                ->label('Save SEO Settings')
                                ->icon(Heroicon::OutlinedCheck)
                                ->color('primary')
                                ->action(fn () => $this->saveKeys(['seo_title', 'seo_description', 'seo_keywords', 'google_site_verification', 'google_analytics_id'], 'SEO settings saved')),
                        ])->columnSpanFull(),
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
                            ->label('Save All Settings')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    /**
     * Saves only a specific subset of keys without requiring or validating unrelated sections.
     *
     * @param  string[]  $keys
     */
    public function saveKeys(array $keys, string $title = 'Settings saved'): void
    {
        $raw = is_array($this->data) ? $this->data : [];
        $before = Setting::values();

        $toSave = [];
        foreach ($keys as $key) {
            $val = isset($raw[$key]) ? trim((string) $raw[$key]) : null;
            if ($key === 'telegram_bot_token' && $val !== null && $val !== '') {
                $val = TelegramNotifier::cleanToken($val);
                $this->data['telegram_bot_token'] = $val;
            }
            if ($key === 'support_whatsapp' && $val !== null && $val !== '') {
                $val = format_whatsapp($val);
                $this->data['support_whatsapp'] = $val;
            }
            if ($val === '' && isset(Setting::DEFAULTS[$key]) && in_array($key, ['store_name', 'currency_symbol', 'usdt_rate', 'price_rounding', 'order_expiry_minutes', 'sms_webhook_secret', 'admin_quick_pin'], true)) {
                $val = Setting::DEFAULTS[$key];
                $this->data[$key] = $val;
            }
            $toSave[$key] = $val;
        }

        Setting::setMany($toSave);

        $pricingChanged = collect(self::PRICING_KEYS)
            ->intersect($keys)
            ->contains(fn (string $key): bool => (float) ($before[$key] ?? 0) !== (float) ($toSave[$key] ?? 0));

        $notification = Notification::make()->title($title)->success();

        if ($pricingChanged) {
            $count = Listings::refreshAllSourced();
            $notification->body("{$count} supplier-sourced product(s) re-priced.");
        }

        $notification->send();
    }

    public function save(): void
    {
        $this->saveKeys(self::KEYS, 'All settings saved');
    }

    protected function sendNtfyTest(): void
    {
        $topic = trim((string) ($this->data['ntfy_topic'] ?? ''));
        if ($topic === '') {
            Notification::make()
                ->title('Enter a secret ntfy Topic name first')
                ->body('For example: sellmetools_awais_786')
                ->warning()
                ->send();

            return;
        }

        Setting::set('ntfy_topic', $topic);

        try {
            $res = Http::timeout(6)
                ->withHeaders([
                    'Title' => 'SellMeTools – ntfy Push Active!',
                    'Priority' => 'high',
                    'Tags' => 'white_check_mark,bell',
                ])
                ->withBody("Your ntfy mobile alerts are connected for topic: {$topic}", 'text/plain')
                ->post('https://ntfy.sh/'.urlencode($topic));

            if ($res->successful()) {
                Notification::make()
                    ->title('Test Notification Sent to ntfy!')
                    ->body("Check the ntfy app on your phone (subscribed to '{$topic}').")
                    ->success()
                    ->send();

                return;
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('ntfy Error: '.$e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Could not send ntfy notification')
            ->danger()
            ->send();
    }

    protected function sendTelegramTest(): void
    {
        $rawToken = trim((string) ($this->data['telegram_bot_token'] ?? ''));
        $token = TelegramNotifier::cleanToken($rawToken);
        $chatId = trim((string) ($this->data['telegram_admin_chat_id'] ?? ''));

        if ($token === '') {
            Notification::make()
                ->title('Enter your Telegram Bot Token first')
                ->body('Copy the HTTP API Token from @BotFather and paste it in the Telegram Bot Token box.')
                ->warning()
                ->send();

            return;
        }

        $this->data['telegram_bot_token'] = $token;
        $notifier = app(TelegramNotifier::class);

        // If Chat ID is empty or user entered @username instead of numeric ID, auto-detect from bot's /start messages!
        if ($chatId === '' || ! preg_match('/^-?\d+$/', $chatId)) {
            $detected = $notifier->detectChatId($token);
            if ($detected !== null) {
                $chatId = $detected;
                $this->data['telegram_admin_chat_id'] = $chatId;
            }
        }

        if ($chatId === '') {
            Notification::make()
                ->title('Could not auto-detect your Chat ID yet')
                ->body('Open your bot in Telegram, press START (or send "hi" to your bot), and click this button again!'.($notifier->lastError ? " ({$notifier->lastError})" : ''))
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        // Save Token & Chat ID immediately
        Setting::setMany([
            'telegram_bot_token' => $token,
            'telegram_admin_chat_id' => $chatId,
        ]);

        $storeName = TelegramNotifier::e((string) ($this->data['store_name'] ?? Setting::get('store_name', 'SellMeTools')));
        $sent = $notifier->send($token, $chatId, "✅ <b>{$storeName}</b>\nTelegram 1-Tap Approve &amp; Decline alerts are active!");

        if (! $sent) {
            // One more attempt: if the user entered the wrong chat ID, try auto-detecting from getUpdates
            $detected = $notifier->detectChatId($token);
            if ($detected !== null && $detected !== $chatId) {
                $chatId = $detected;
                $this->data['telegram_admin_chat_id'] = $chatId;
                Setting::set('telegram_admin_chat_id', $chatId);
                $sent = $notifier->send($token, $chatId, "✅ <b>{$storeName}</b>\nTelegram 1-Tap Approve &amp; Decline alerts are active!");
            }
        }

        if ($sent) {
            $notifier->registerWebhook($token);
            Notification::make()
                ->title('Telegram Connected & Saved!')
                ->body("Test message sent to Chat ID {$chatId} and 1-Tap webhook registered.")
                ->success()
                ->send();

            return;
        }

        $err = $notifier->lastError ?: 'Unknown error';
        $hint = str_contains(strtolower($err), 'chat not found')
            ? 'Open your bot in Telegram, press START (or send "hi" to it), then click this button again.'
            : (str_contains(strtolower($err), 'unauthorized')
                ? 'Your Bot Token was rejected by Telegram. Copy the exact token from @BotFather.'
                : 'Make sure you pressed START inside your Telegram bot first.');

        Notification::make()
            ->title("Telegram Error: {$err}")
            ->body($hint)
            ->danger()
            ->persistent()
            ->send();
    }
}
