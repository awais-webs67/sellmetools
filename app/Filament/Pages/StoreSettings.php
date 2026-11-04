<?php

namespace App\Filament\Pages;

use App\Filament\Support\GalleryPicker;
use App\Filament\Support\Listings;
use App\Http\Controllers\SeoController;
use App\Models\Product;
use App\Models\Setting;
use App\Services\DatabaseBackupService;
use App\Services\EmailNotifier;
use App\Services\OrderService;
use App\Services\TelegramNotifier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Pro-level Tabbed Store Settings backed by the `settings` table.
 * Each tab has its own independent Save button so the admin can save any section
 * separately without filling unrelated optional fields.
 *
 * @property-read Schema $form
 */
class StoreSettings extends Page
{
    /** Keys managed by this page (all live in Setting::DEFAULTS). */
    protected const KEYS = [
        'store_name', 'store_tagline', 'store_logo', 'store_theme', 'shop_default_sort', 'home_default_sort', 'announcement', 'currency_symbol',
        'promo_popup_enabled', 'promo_popup_type', 'promo_popup_badge', 'promo_popup_title', 'promo_popup_body', 'promo_popup_image', 'promo_popup_cta_text', 'promo_popup_cta_url', 'promo_popup_version',
        'home_hero_badge', 'home_hero_title', 'home_hero_subtitle', 'home_about_heading', 'home_guide_heading', 'home_seo_content',
        'shop_seo_title', 'shop_seo_description',
        'usdt_rate', 'price_rounding', 'order_expiry_minutes',
        'sms_webhook_secret', 'admin_quick_pin',
        'greenapi_instance_id', 'greenapi_api_token', 'ntfy_topic',
        'whatsapp_callmebot_apikey', 'whatsapp_admin_webhook_url',
        'telegram_bot_token', 'telegram_admin_chat_id', 'telegram_store_bot_enabled',
        'support_whatsapp', 'support_telegram', 'support_email',
        'seo_title', 'seo_description', 'seo_keywords', 'google_site_verification', 'bing_site_verification', 'indexnow_key', 'google_analytics_id',
        'mail_enabled', 'mail_send_order_delivered',
        'mail_host', 'mail_port', 'mail_username', 'mail_password',
        'mail_encryption', 'mail_from_address', 'mail_from_name',
        'backup_enabled', 'backup_frequency', 'backup_email',
    ];

    /** Keys that the SEO & Content Specialist role is allowed to save. */
    protected const SEO_STAFF_KEYS = [
        'store_name', 'store_tagline', 'store_logo', 'store_theme', 'shop_default_sort', 'home_default_sort', 'announcement',
        'promo_popup_enabled', 'promo_popup_type', 'promo_popup_badge', 'promo_popup_title', 'promo_popup_body', 'promo_popup_image', 'promo_popup_cta_text', 'promo_popup_cta_url', 'promo_popup_version',
        'home_hero_badge', 'home_hero_title', 'home_hero_subtitle', 'home_about_heading', 'home_guide_heading', 'home_seo_content',
        'shop_seo_title', 'shop_seo_description',
        'seo_title', 'seo_description', 'seo_keywords', 'google_site_verification', 'bing_site_verification', 'indexnow_key', 'google_analytics_id',
    ];

    /** Changing these re-prices every supplier-sourced product. */
    protected const PRICING_KEYS = ['usdt_rate', 'price_rounding'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Store Settings';

    protected static ?string $title = 'Store Settings';

    protected static ?string $slug = 'settings';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->isSeoStaff());
    }

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Boolean toggles, stored as '1' / '0' strings. */
    protected const TOGGLE_KEYS = ['mail_enabled', 'mail_send_order_delivered', 'telegram_store_bot_enabled', 'backup_enabled', 'promo_popup_enabled'];

    public function mount(): void
    {
        $values = array_intersect_key(Setting::values(), array_flip(self::KEYS));

        foreach (self::TOGGLE_KEYS as $key) {
            $default = in_array($key, ['backup_enabled', 'promo_popup_enabled'], true) ? '0' : '1';
            $values[$key] = (string) ($values[$key] ?? $default) === '1';
        }

        // Never send the stored SMTP password back to the browser.
        $values['mail_password'] = '';
        $values['test_mail_recipient'] = $values['support_email'] ?: (auth()->user()?->email ?? 'contact@sellmetools.com');
        if (empty($values['backup_email'])) {
            $values['backup_email'] = $values['support_email'] ?: (auth()->user()?->email ?? '');
        }

        $this->form->fill($values);
    }

    protected function getHeaderActions(): array
    {
        if (auth()->user()?->isSeoStaff()) {
            return [
                Action::make('clearSeoCache')
                    ->label('Clear SEO & Site Cache')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('primary')
                    ->action(function (): void {
                        try {
                            Artisan::call('view:clear');
                            Artisan::call('cache:clear');
                        } catch (\Throwable) {
                        }

                        Notification::make()
                            ->title('SEO & Site Content Cache Cleared!')
                            ->body('All updated titles, meta tags, sitemaps, and page content are now live.')
                            ->success()
                            ->send();
                    }),
            ];
        }

        if (! auth()->user()?->isSuperAdmin()) {
            return [];
        }

        return [
            Action::make('downloadBackupHeader')
                ->label('Download SQL Backup')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->downloadDatabaseBackup()),
            Action::make('syncServerUpdate')
                ->label('Update Site, Migrate DB & Clear Cache')
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
                        app(OrderService::class)->expireStale();
                        Product::query()->each(fn (Product $p) => Listings::refresh($p));
                        if ($tgToken = (string) Setting::get('telegram_bot_token', '')) {
                            app(TelegramNotifier::class)->registerWebhook($tgToken);
                        }
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Migration / Cache Error')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Database Migrated & Cache Cleared!')
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
                Tabs::make('StoreSettingsTabs')
                    ->persistTabInQueryString()
                    ->tabs([
                        // ==========================================
                        // TAB 1: GENERAL & BRANDING
                        // ==========================================
                        Tab::make('General & Branding')
                            ->icon(Heroicon::OutlinedBuildingStorefront)
                            ->visible(fn (): bool => (bool) (auth()->user()?->isSuperAdmin() || auth()->user()?->isSeoStaff()))
                            ->schema([
                                Section::make('Store Identity, Theme & Logo')
                                    ->description('Configure your brand name, storefront theme color, logo, and announcement bar.')
                                    ->columns(['default' => 1, 'sm' => 2])
                                    ->schema([
                                        TextInput::make('store_name')
                                            ->label('Store Name')
                                            ->maxLength(100),
                                        Select::make('store_theme')
                                            ->label('Website Color Theme')
                                            ->options([
                                                'black' => 'Pure Black & White (#000000)',
                                                'crimson' => 'Original Wine Red (#9B1B30)',
                                            ])
                                            ->default('black')
                                            ->native(false)
                                            ->required(),
                                        Select::make('shop_default_sort')
                                            ->label('Shop Page (/shop) Default Product Order')
                                            ->options([
                                                'alpha' => 'Alphabetical Order (A to Z)',
                                                'random' => 'Random Order (Changes on Refresh)',
                                                'popular' => 'Best Selling First',
                                                'price_asc' => 'Price: Low to High',
                                                'newest' => 'Newest Products First',
                                            ])
                                            ->default('alpha')
                                            ->native(false)
                                            ->required()
                                            ->helperText('Choose whether products on /shop load in Alphabetical (A-Z) or Random order by default.'),
                                        Select::make('home_default_sort')
                                            ->label('Homepage (/) Default Product Order')
                                            ->options([
                                                'random' => 'Random Order (Changes on Refresh)',
                                                'alpha' => 'Alphabetical Order (A to Z)',
                                                'popular' => 'Best Selling First',
                                                'newest' => 'Newest Products First',
                                            ])
                                            ->default('random')
                                            ->native(false)
                                            ->required()
                                            ->helperText('Choose whether products on the Homepage load in Random or Alphabetical (A-Z) order.'),
                                        TextInput::make('currency_symbol')
                                            ->label('Currency Symbol')
                                            ->visible(fn (): bool => (bool) auth()->user()?->isSuperAdmin())
                                            ->maxLength(10),
                                        TextInput::make('store_tagline')
                                            ->label('Short Tagline (Footer & Default Store Description)')
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
                                                ->label('Save Branding, Theme & Product Order')
                                                ->icon(Heroicon::OutlinedCheck)
                                                ->color('primary')
                                                ->action(fn () => $this->saveKeys(['store_name', 'store_theme', 'shop_default_sort', 'home_default_sort', 'currency_symbol', 'store_tagline', 'store_logo', 'announcement'], 'Branding, theme & product sorting saved')),
                                        ])->columnSpanFull(),
                                    ]),

                                Section::make('Promotional Welcome Popup / First-Time Visitor Banner')
                                    ->description('Display a promotional banner (image, text, or both) with a custom link (e.g. your WhatsApp/Telegram channel or special offer) when visitors open your store. Visitors can click "Don\'t show again" to dismiss it permanently.')
                                    ->icon(Heroicon::OutlinedMegaphone)
                                    ->columns(['default' => 1, 'sm' => 2])
                                    ->schema([
                                        Toggle::make('promo_popup_enabled')
                                            ->label('Enable Promotional Welcome Popup on Storefront')
                                            ->helperText('Turn ON to show this promotional popup to visitors on the storefront.')
                                            ->default(false)
                                            ->columnSpanFull(),
                                        Select::make('promo_popup_type')
                                            ->label('Banner Display Mode')
                                            ->options([
                                                'both' => 'Image + Text & CTA Button',
                                                'image' => 'Image Banner + CTA Button Only',
                                                'text' => 'Text Announcement + CTA Button Only',
                                            ])
                                            ->default('both')
                                            ->native(false)
                                            ->required(),
                                        TextInput::make('promo_popup_version')
                                            ->label('Campaign Version / Reset Key')
                                            ->placeholder('v1')
                                            ->default('v1')
                                            ->maxLength(40)
                                            ->helperText('Change to e.g. v2 or v3 when starting a new promotion so visitors who dismissed an older banner see the new one.'),
                                        TextInput::make('promo_popup_badge')
                                            ->label('Top Eyebrow Badge (Optional)')
                                            ->placeholder('OFFICIAL CHANNEL & UPDATES')
                                            ->maxLength(80),
                                        TextInput::make('promo_popup_title')
                                            ->label('Popup Title / Heading')
                                            ->placeholder('Join Our Official WhatsApp Channel for Exclusive Discounts')
                                            ->maxLength(180),
                                        Textarea::make('promo_popup_body')
                                            ->label('Promotional Message / Description')
                                            ->placeholder('Get instant updates on restocks, flash deals, and new AI tools & digital subscriptions directly on your phone.')
                                            ->rows(3)
                                            ->maxLength(600)
                                            ->columnSpanFull(),
                                        GalleryPicker::make('promo_popup_image', 'Promotional Banner Image (Optional — Upload or Pick from Gallery)', 'gallery'),
                                        TextInput::make('promo_popup_cta_text')
                                            ->label('Action Button Label (Optional)')
                                            ->placeholder('Join Official Channel')
                                            ->maxLength(80),
                                        TextInput::make('promo_popup_cta_url')
                                            ->label('Action Button URL / Channel Link (Optional)')
                                            ->placeholder('https://whatsapp.com/channel/...')
                                            ->maxLength(500)
                                            ->columnSpanFull(),
                                        Actions::make([
                                            Action::make('savePromoPopupSection')
                                                ->label('Save Promotional Banner Settings')
                                                ->icon(Heroicon::OutlinedCheck)
                                                ->color('primary')
                                                ->action(fn () => $this->saveKeys([
                                                    'promo_popup_enabled', 'promo_popup_type', 'promo_popup_version',
                                                    'promo_popup_badge', 'promo_popup_title', 'promo_popup_body',
                                                    'promo_popup_image', 'promo_popup_cta_text', 'promo_popup_cta_url',
                                                ], 'Promotional welcome banner settings saved')),
                                        ])->columnSpanFull(),
                                    ]),

                                Section::make('Public Support Contacts')
                                    ->visible(fn (): bool => (bool) auth()->user()?->isSuperAdmin())
                                    ->description('Displayed in the website footer, Contact page, and customer receipts.')
                                    ->columns(['default' => 1, 'sm' => 3])
                                    ->schema([
                                        TextInput::make('support_whatsapp')
                                            ->label('WhatsApp Number')
                                            ->placeholder('923001234567'),
                                        TextInput::make('support_telegram')
                                            ->label('Telegram Username')
                                            ->placeholder('@sellmetools'),
                                        TextInput::make('support_email')
                                            ->label('Support Email')
                                            ->placeholder('contact@sellmetools.com'),
                                        Actions::make([
                                            Action::make('saveSupportSection')
                                                ->label('Save Support Contacts')
                                                ->icon(Heroicon::OutlinedCheck)
                                                ->color('primary')
                                                ->action(fn () => $this->saveKeys(['support_whatsapp', 'support_telegram', 'support_email'], 'Support contacts saved')),
                                        ])->columnSpanFull(),
                                    ]),
                            ]),

                        // ==========================================
                        // TAB 2: SITE CONTENT & COPY
                        // ==========================================
                        Tab::make('Site Content & Copy')
                            ->icon(Heroicon::OutlinedDocumentText)
                            ->visible(fn (): bool => (bool) (auth()->user()?->isSuperAdmin() || auth()->user()?->isSeoStaff()))
                            ->schema([
                                Section::make('Homepage & Shop Site Content (Hero, Headings & Rich SEO Copy)')
                                    ->description('Edit the main headings, intro text, and rich SEO guide content shown on the Homepage and /shop catalog page.')
                                    ->columns(['default' => 1, 'sm' => 2])
                                    ->schema([
                                        TextInput::make('home_hero_badge')
                                            ->label('Homepage Hero Top Badge')
                                            ->placeholder('Global Digital Marketplace · Instant Worldwide Delivery')
                                            ->maxLength(160),
                                        TextInput::make('home_hero_title')
                                            ->label('Homepage Main H1 Heading')
                                            ->placeholder('Buy AI Tools & Digital Subscriptions Online')
                                            ->maxLength(180),
                                        Textarea::make('home_hero_subtitle')
                                            ->label('Homepage Hero Intro Paragraph (Optional — Leave blank for default)')
                                            ->placeholder('Welcome to SellMeTools — buy genuine AI tools, cheap subscriptions, premium software and cloud accounts online...')
                                            ->rows(2)
                                            ->maxLength(600)
                                            ->columnSpanFull(),
                                        TextInput::make('home_about_heading')
                                            ->label('Homepage About Section Heading (H2)')
                                            ->placeholder('Pakistan’s Trusted Marketplace for AI Tools & Subscriptions')
                                            ->maxLength(180),
                                        TextInput::make('home_guide_heading')
                                            ->label('Homepage Buying Guide Heading (H2)')
                                            ->placeholder('Buy AI Tools & Digital Subscriptions Online — USDT & Local PKR Prices')
                                            ->maxLength(180),
                                        RichEditor::make('home_seo_content')
                                            ->label('Homepage Custom SEO Article / Buying Guide Content (Optional)')
                                            ->toolbarButtons([
                                                'bold', 'italic', 'underline', 'strike',
                                                'h2', 'h3', 'bulletList', 'orderedList',
                                                'blockquote', 'link', 'undo', 'redo',
                                            ])
                                            ->helperText('Write rich SEO content, keyword guides (e.g. ChatGPT Plus cheap subscription, Canva Pro, etc.), or internal links to display in the Homepage Buying Guide section.')
                                            ->columnSpanFull(),
                                        TextInput::make('shop_seo_title')
                                            ->label('Full Shop Page (/shop) SEO Title')
                                            ->placeholder('Shop AI Tools & Cheap Subscriptions Online')
                                            ->maxLength(255)
                                            ->columnSpanFull(),
                                        Textarea::make('shop_seo_description')
                                            ->label('Full Shop Page (/shop) Meta Description')
                                            ->rows(2)
                                            ->maxLength(500)
                                            ->columnSpanFull(),
                                        Actions::make([
                                            Action::make('saveSiteContentSection')
                                                ->label('Save Homepage & Shop Site Content')
                                                ->icon(Heroicon::OutlinedCheck)
                                                ->color('primary')
                                                ->action(fn () => $this->saveKeys([
                                                    'home_hero_badge', 'home_hero_title', 'home_hero_subtitle',
                                                    'home_about_heading', 'home_guide_heading', 'home_seo_content',
                                                    'shop_seo_title', 'shop_seo_description',
                                                ], 'Homepage & Shop site content saved')),
                                        ])->columnSpanFull(),
                                    ]),
                            ]),

                        // ==========================================
                        // TAB 3: SEO, GOOGLE & BING
                        // ==========================================
                        Tab::make('SEO, Google & Bing')
                            ->icon(Heroicon::OutlinedMagnifyingGlassCircle)
                            ->visible(fn (): bool => (bool) (auth()->user()?->isSuperAdmin() || auth()->user()?->isSeoStaff()))
                            ->schema([
                                Section::make('SEO, Google Search & Bing / IndexNow (Copilot & ChatGPT Indexing)')
                                    ->description('Optimize your store for Google, Bing, Microsoft Copilot & ChatGPT Search. Live XML Sitemap: '.url('/sitemap.xml').' | AI Index: '.url('/llms.txt'))
                                    ->columns(['default' => 1, 'sm' => 2])
                                    ->schema([
                                        TextInput::make('seo_title')
                                            ->label('Homepage SEO Title')
                                            ->placeholder('Buy AI Tools & Cheap Digital Subscriptions Online')
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
                                            ->placeholder('Paste token or meta tag'),
                                        TextInput::make('bing_site_verification')
                                            ->label('Bing Webmaster Verification Code (msvalidate.01 — Optional)')
                                            ->placeholder('Paste token or <meta name="msvalidate.01" ...>'),
                                        TextInput::make('indexnow_key')
                                            ->label('IndexNow API Key (Instant Bing / Copilot / ChatGPT Indexing)')
                                            ->placeholder('a7f9c2e4b8d106359a2c4e6f8b0d2e4a')
                                            ->helperText('Automatically served at '.url('/'.Setting::get('indexnow_key', 'a7f9c2e4b8d106359a2c4e6f8b0d2e4a').'.txt')),
                                        TextInput::make('google_analytics_id')
                                            ->label('Google Analytics 4 ID (Optional)')
                                            ->placeholder('G-XXXXXXXXXX'),
                                        Actions::make([
                                            Action::make('saveSeoSection')
                                                ->label('Save SEO Settings')
                                                ->icon(Heroicon::OutlinedCheck)
                                                ->color('primary')
                                                ->action(fn () => $this->saveKeys(['seo_title', 'seo_description', 'seo_keywords', 'google_site_verification', 'bing_site_verification', 'indexnow_key', 'google_analytics_id'], 'SEO settings saved')),
                                            Action::make('pingIndexNow')
                                                ->label('Submit All Site URLs to Bing & ChatGPT (IndexNow)')
                                                ->icon(Heroicon::OutlinedPaperAirplane)
                                                ->color('gray')
                                                ->action(function (): void {
                                                    $this->saveKeys(['seo_title', 'seo_description', 'seo_keywords', 'google_site_verification', 'bing_site_verification', 'indexnow_key', 'google_analytics_id'], 'SEO settings saved');
                                                    $res = SeoController::submitAllToIndexNow();
                                                    if ($res['success']) {
                                                        Notification::make()
                                                            ->title('IndexNow Batch Submitted!')
                                                            ->body($res['message'])
                                                            ->success()
                                                            ->send();
                                                    } else {
                                                        Notification::make()
                                                            ->title('IndexNow Notice')
                                                            ->body($res['message'])
                                                            ->warning()
                                                            ->persistent()
                                                            ->send();
                                                    }
                                                }),
                                        ])->columnSpanFull(),
                                    ]),
                            ]),

                        // ==========================================
                        // TAB 4: PRICING & DOLLAR RATE
                        // ==========================================
                        Tab::make('Pricing & Dollar Rate')
                            ->icon(Heroicon::OutlinedCurrencyDollar)
                            ->visible(fn (): bool => (bool) auth()->user()?->isSuperAdmin())
                            ->schema([
                                Section::make('USDT Conversion & Automatic Re-Pricing')
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
                            ]),

                        // ==========================================
                        // TAB 5: TELEGRAM & MOBILE ALERTS
                        // ==========================================
                        Tab::make('Telegram & Mobile Alerts')
                            ->icon(Heroicon::OutlinedBellAlert)
                            ->visible(fn (): bool => (bool) auth()->user()?->isSuperAdmin())
                            ->schema([
                                Section::make('Telegram Admin Notifications & 1-Tap Approve/Decline')
                                    ->description('Receive instant order & payment alerts in Telegram with 1-Tap Approve & Decline buttons.')
                                    ->columns(['default' => 1, 'sm' => 2])
                                    ->schema([
                                        TextInput::make('telegram_bot_token')
                                            ->label('Telegram Bot Token (from @BotFather)')
                                            ->placeholder('123456789:AAH...')
                                            ->autocomplete(false),
                                        TextInput::make('telegram_admin_chat_id')
                                            ->label('Admin Numeric Chat ID (Leave blank to Auto-Detect!)')
                                            ->placeholder('e.g. 582910384 (or click Auto-Detect below)'),
                                        Actions::make([
                                            Action::make('saveTelegramSection')
                                                ->label('Save Telegram Settings')
                                                ->icon(Heroicon::OutlinedCheck)
                                                ->color('gray')
                                                ->action(fn () => $this->saveKeys(['telegram_bot_token', 'telegram_admin_chat_id'], 'Telegram settings saved')),
                                            Action::make('testTelegram')
                                                ->label('Auto-Detect Chat ID & Send Test Notification')
                                                ->icon(Heroicon::OutlinedPaperAirplane)
                                                ->color('primary')
                                                ->action(fn () => $this->sendTelegramTest()),
                                        ])->columnSpanFull(),
                                    ]),

                                Section::make('1-Tap WhatsApp, ntfy Mobile Push & SMS Webhook')
                                    ->description('Instant phone notifications and 1-Tap approval PIN security.')
                                    ->columns(['default' => 1, 'sm' => 2])
                                    ->schema([
                                        TextInput::make('support_whatsapp')
                                            ->label('Admin WhatsApp Number (Exact Format: 923XXXXXXXXX)')
                                            ->placeholder('923001234567'),
                                        TextInput::make('admin_quick_pin')
                                            ->label('Admin 1-Tap WhatsApp PIN')
                                            ->placeholder('7860')
                                            ->maxLength(20)
                                            ->helperText('Protects 1-tap WhatsApp/Telegram manual delivery links.'),
                                        TextInput::make('ntfy_topic')
                                            ->label('ntfy.sh Free Mobile Push Topic (Optional)')
                                            ->placeholder('e.g. sellmetools_awais_orders_786'),
                                        TextInput::make('sms_webhook_secret')
                                            ->label('SMS Webhook Secret Key (Optional)')
                                            ->placeholder('smt_auto_pay_secret_2026'),
                                        TextInput::make('greenapi_instance_id')
                                            ->label('Green-API Instance ID (Optional)')
                                            ->placeholder('e.g. 7103948576'),
                                        TextInput::make('greenapi_api_token')
                                            ->label('Green-API Token (Optional)')
                                            ->autocomplete(false),
                                        Actions::make([
                                            Action::make('saveWhatsappSection')
                                                ->label('Save Mobile & WhatsApp Settings')
                                                ->icon(Heroicon::OutlinedCheck)
                                                ->color('primary')
                                                ->action(fn () => $this->saveKeys([
                                                    'support_whatsapp', 'admin_quick_pin', 'ntfy_topic', 'sms_webhook_secret',
                                                    'greenapi_instance_id', 'greenapi_api_token',
                                                    'whatsapp_callmebot_apikey', 'whatsapp_admin_webhook_url',
                                                ], 'Mobile & WhatsApp settings saved')),
                                            Action::make('testNtfyPush')
                                                ->label('Test ntfy Push')
                                                ->icon(Heroicon::OutlinedBellAlert)
                                                ->color('gray')
                                                ->action(fn () => $this->sendNtfyTest()),
                                        ])->columnSpanFull(),
                                    ]),
                            ]),

                        // ==========================================
                        // TAB 6: EMAIL & AUTO DATABASE BACKUPS
                        // ==========================================
                        Tab::make('Email & Auto Backups')
                            ->icon(Heroicon::OutlinedEnvelope)
                            ->visible(fn (): bool => (bool) auth()->user()?->isSuperAdmin())
                            ->schema([
                                Section::make('Automated Database Email Backups (Weekly / Monthly / Daily)')
                                    ->description('Automatically email a complete SQL database backup (Products, Orders, Customers, Stock & Settings) to your admin email on schedule — or send/download one immediately.')
                                    ->columns(['default' => 1, 'sm' => 3])
                                    ->schema([
                                        Toggle::make('backup_enabled')
                                            ->label('Enable Automated Email Backups')
                                            ->default(false)
                                            ->helperText(fn () => Setting::get('backup_last_sent_at')
                                                ? 'Last backup sent: '.Setting::get('backup_last_sent_at')
                                                : 'No scheduled backup sent yet.')
                                            ->columnSpanFull(),
                                        Select::make('backup_frequency')
                                            ->label('Backup Schedule')
                                            ->options([
                                                'weekly' => 'Every Week (Recommended)',
                                                'monthly' => 'Every Month',
                                                'daily' => 'Every Day',
                                            ])
                                            ->default('weekly')
                                            ->native(false),
                                        TextInput::make('backup_email')
                                            ->label('Backup Destination Email Address')
                                            ->email()
                                            ->placeholder('your-backup-email@gmail.com')
                                            ->helperText('The SQL database backup file will be emailed as an attachment to this address.')
                                            ->columnSpan(['default' => 1, 'sm' => 2]),
                                        Actions::make([
                                            Action::make('saveBackupSection')
                                                ->label('Save Backup Schedule')
                                                ->icon(Heroicon::OutlinedCheck)
                                                ->color('primary')
                                                ->action(fn () => $this->saveKeys(['backup_enabled', 'backup_frequency', 'backup_email'], 'Automated backup settings saved')),
                                            Action::make('sendBackupEmailNow')
                                                ->label('Send Backup to Email Now')
                                                ->icon(Heroicon::OutlinedPaperAirplane)
                                                ->color('success')
                                                ->action(function (): void {
                                                    $this->saveKeys(['backup_enabled', 'backup_frequency', 'backup_email'], 'Backup settings saved');
                                                    $overrides = is_array($this->data) ? $this->data : [];
                                                    $recipient = trim((string) ($overrides['backup_email'] ?? ''));
                                                    $res = app(DatabaseBackupService::class)->sendBackupEmail($recipient, $overrides);
                                                    if ($res['success']) {
                                                        Notification::make()->title('Database Backup Emailed!')->body($res['message'])->success()->send();
                                                    } else {
                                                        Notification::make()->title('Backup Email Failed')->body($res['message'])->danger()->persistent()->send();
                                                    }
                                                }),
                                            Action::make('downloadSqlBackupNow')
                                                ->label('Download SQL Backup File')
                                                ->icon(Heroicon::OutlinedArrowDownTray)
                                                ->color('gray')
                                                ->action(fn (): StreamedResponse => $this->downloadDatabaseBackup()),
                                        ])->columnSpanFull(),
                                    ]),

                                Section::make('Email & SMTP Configuration (Order Delivery, Password Reset & Backups)')
                                    ->description('Configure SMTP to email delivered credentials to customers, send password reset links, and deliver automated database backups.')
                                    ->columns(['default' => 1, 'sm' => 2])
                                    ->schema([
                                        Toggle::make('mail_enabled')
                                            ->label('Enable Email Notifications via SMTP')
                                            ->default(true),
                                        Toggle::make('mail_send_order_delivered')
                                            ->label('Auto-Email Delivered Credentials to Customers')
                                            ->default(true),
                                        TextInput::make('mail_host')
                                            ->label('SMTP Host')
                                            ->placeholder('smtp.hostinger.com'),
                                        TextInput::make('mail_port')
                                            ->label('SMTP Port')
                                            ->numeric()
                                            ->placeholder('465 or 587')
                                            ->default('465'),
                                        Select::make('mail_encryption')
                                            ->label('Encryption')
                                            ->options([
                                                'ssl' => 'SSL (Port 465)',
                                                'tls' => 'TLS / STARTTLS (Port 587)',
                                                'none' => 'None (Port 25 / 2525)',
                                            ])
                                            ->default('ssl'),
                                        TextInput::make('mail_username')
                                            ->label('SMTP Username / Email')
                                            ->placeholder('contact@sellmetools.com')
                                            ->autocomplete(false)
                                            ->extraInputAttributes(['autocomplete' => 'off', 'data-lpignore' => 'true']),
                                        TextInput::make('mail_password')
                                            ->label('SMTP Password / App Password')
                                            ->autocomplete(false)
                                            ->extraInputAttributes(['autocomplete' => 'off', 'data-lpignore' => 'true'])
                                            ->placeholder(fn () => filled(EmailNotifier::decryptPassword((string) Setting::get('mail_password'))) ? '✓ Saved securely — paste new password here to change' : 'Enter SMTP password'),
                                        TextInput::make('mail_from_address')
                                            ->label('From Email Address (Optional)')
                                            ->email()
                                            ->placeholder('Leave empty to use SMTP Username'),
                                        TextInput::make('mail_from_name')
                                            ->label('From Display Name')
                                            ->placeholder('SellMeTools'),
                                        TextInput::make('test_mail_recipient')
                                            ->label('Send Test Email To')
                                            ->placeholder('your-email@gmail.com')
                                            ->autocomplete(false),
                                        Actions::make([
                                            Action::make('saveMailSection')
                                                ->label('Save Email & SMTP Settings')
                                                ->icon(Heroicon::OutlinedCheck)
                                                ->color('primary')
                                                ->action(fn () => $this->saveKeys([
                                                    'mail_enabled', 'mail_send_order_delivered', 'mail_host', 'mail_port',
                                                    'mail_encryption', 'mail_username', 'mail_password', 'mail_from_address', 'mail_from_name',
                                                ], 'Email & SMTP settings saved')),
                                            Action::make('testSmtpEmail')
                                                ->label('Save & Send Test Email Now')
                                                ->icon(Heroicon::OutlinedPaperAirplane)
                                                ->color('gray')
                                                ->action(function (): void {
                                                    $overrides = is_array($this->data) ? $this->data : [];
                                                    $recipient = trim((string) ($overrides['test_mail_recipient'] ?? ''))
                                                        ?: (Setting::get('support_email') ?: (auth()->user()?->email ?: trim((string) ($overrides['mail_username'] ?? ''))));

                                                    $this->saveKeys([
                                                        'mail_enabled', 'mail_send_order_delivered', 'mail_host', 'mail_port',
                                                        'mail_encryption', 'mail_username', 'mail_password', 'mail_from_address', 'mail_from_name',
                                                    ], 'SMTP settings saved');

                                                    $res = app(EmailNotifier::class)->sendTest($recipient, $overrides);
                                                    if ($res['success']) {
                                                        Notification::make()->title('Email Sent')->body($res['message'])->success()->send();
                                                    } else {
                                                        Notification::make()->title('SMTP Test Failed')->body($res['message'])->danger()->persistent()->send();
                                                    }
                                                }),
                                        ])->columnSpanFull(),
                                    ]),
                            ]),
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
     * Stream an on-demand SQL database dump directly to the admin's browser.
     */
    public function downloadDatabaseBackup(): StreamedResponse
    {
        $sql = app(DatabaseBackupService::class)->generateSqlDump();
        $filename = 'sellmetools-backup-'.now()->format('Y-m-d_His').'.sql';

        return response()->streamDownload(function () use ($sql): void {
            echo $sql;
        }, $filename, [
            'Content-Type' => 'application/sql; charset=UTF-8',
        ]);
    }

    /**
     * Saves only a specific subset of keys without requiring or validating unrelated sections.
     *
     * @param  string[]  $keys
     */
    public function saveKeys(array $keys, string $title = 'Settings saved'): void
    {
        if (auth()->user()?->isSeoStaff()) {
            $keys = array_values(array_intersect($keys, self::SEO_STAFF_KEYS));
        }
        $raw = is_array($this->data) ? $this->data : [];
        $before = Setting::values();

        $toSave = [];
        foreach ($keys as $key) {
            if (in_array($key, self::TOGGLE_KEYS, true)) {
                $toSave[$key] = ! empty($raw[$key]) ? '1' : '0';

                continue;
            }
            if ($key === 'mail_password') {
                $plain = (string) ($raw[$key] ?? '');
                if ($plain !== '') {
                    $toSave[$key] = EmailNotifier::encryptPassword($plain);
                    $this->data['mail_password'] = '';
                }

                continue; // empty = keep the currently saved password
            }
            $rawVal = $raw[$key] ?? null;
            if (is_array($rawVal)) {
                $rawVal = reset($rawVal) ?: '';
            }
            $val = $rawVal !== null ? trim((string) $rawVal) : null;
            if ($key === 'telegram_bot_token' && $val !== null && $val !== '') {
                $val = TelegramNotifier::cleanToken($val);
                $this->data['telegram_bot_token'] = $val;
            }
            if ($key === 'support_whatsapp' && $val !== null && $val !== '') {
                $val = format_whatsapp($val);
                $this->data['support_whatsapp'] = $val;
            }
            if ($val === '' && isset(Setting::DEFAULTS[$key]) && in_array($key, ['store_name', 'currency_symbol', 'usdt_rate', 'price_rounding', 'order_expiry_minutes', 'sms_webhook_secret', 'admin_quick_pin', 'backup_frequency'], true)) {
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
                    'Title' => 'SellMeTools, ntfy Push Active!',
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
        $testHtml = "✅ <b>{$storeName} — Admin 1-Tap Notifications Active!</b>\n"
            .'You will receive instant payment &amp; order alerts with 1-Tap Approve/Decline buttons here.';
        $sent = $notifier->send($token, $chatId, $testHtml);

        if (! $sent) {
            $detected = $notifier->detectChatId($token);
            if ($detected !== null && $detected !== $chatId) {
                $chatId = $detected;
                $this->data['telegram_admin_chat_id'] = $chatId;
                Setting::set('telegram_admin_chat_id', $chatId);
                $sent = $notifier->send($token, $chatId, $testHtml);
            }
        }

        if ($sent) {
            $notifier->registerWebhook($token);
            Notification::make()
                ->title('Telegram Admin Alerts Connected!')
                ->body("Test notification sent to Chat ID {$chatId} and 1-Tap webhook registered.")
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
