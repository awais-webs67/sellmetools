<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Setting;
use App\Services\GoogleAnalyticsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;

class GoogleAnalytics extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Google Analytics';

    protected static ?string $title = 'Google Analytics (GA4)';

    protected static ?string $slug = 'google-analytics';

    protected string $view = 'filament.pages.google-analytics';

    public string $preset = '7d';

    protected bool $forceRefresh = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->isSeoStaff());
    }

    public function setPreset(string $preset): void
    {
        if (in_array($preset, ['today', '7d', '30d', '90d'], true)) {
            $this->preset = $preset;
        }
    }

    protected function getHeaderActions(): array
    {
        $isSuperAdmin = (bool) auth()->user()?->isSuperAdmin();

        $actions = [
            Action::make('configureGa4')
                ->label('Configure GA4')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('primary')
                ->modalWidth('2xl')
                ->modalHeading('Google Analytics 4 (GA4) & Tag Manager Setup')
                ->modalDescription('Configure your storefront GA4 Measurement ID (G-...) and optionally connect your GA4 Property ID + Service Account JSON to stream live visitor & traffic charts directly into this Admin Panel.')
                ->fillForm(fn (): array => [
                    'google_analytics_enabled' => (string) Setting::get('google_analytics_enabled', '1') === '1',
                    'google_analytics_id' => (string) Setting::get('google_analytics_id', ''),
                    'gtm_container_id' => (string) Setting::get('gtm_container_id', ''),
                    'ga4_property_id' => (string) Setting::get('ga4_property_id', ''),
                    'ga4_service_account_json' => '',
                ])
                ->schema([
                    Toggle::make('google_analytics_enabled')
                        ->label('Enable Google Analytics 4 & E-Commerce Tracking on Storefront')
                        ->helperText('Automatically tracks page_view, view_item (product page), begin_checkout, and purchase (completed orders with PKR revenue).')
                        ->default(true),
                    Grid::make(['default' => 1, 'sm' => 2])->schema([
                        TextInput::make('google_analytics_id')
                            ->label('GA4 Measurement ID (or paste full gtag code)')
                            ->placeholder('G-XXXXXXXXXX')
                            ->helperText('Found in Google Analytics → Admin → Data Streams → Web Stream.'),
                        TextInput::make('gtm_container_id')
                            ->label('Google Tag Manager ID (Optional)')
                            ->placeholder('GTM-XXXXXXX')
                            ->helperText('Optional GTM container loaded non-blockingly.'),
                    ]),
                    TextInput::make('ga4_property_id')
                        ->label('GA4 Numeric Property ID (Optional — for Live Admin Charts)')
                        ->placeholder('e.g. 412345678')
                        ->helperText('Found in Google Analytics → Admin → Property Details (top-right numeric Property ID).')
                        ->visible($isSuperAdmin),
                    Textarea::make('ga4_service_account_json')
                        ->label('Google Cloud Service Account JSON Key (Optional — Encrypted)')
                        ->rows(4)
                        ->placeholder(fn (): string => filled(Setting::decryptSecret((string) Setting::get('ga4_service_account_json', '')))
                            ? '✓ Service Account JSON is saved securely — paste new JSON only if replacing it'
                            : '{"type":"service_account","project_id":"...","private_key":"-----BEGIN PRIVATE KEY-----...","client_email":"..."}')
                        ->helperText('Enable "Google Analytics Data API" in Google Cloud, create a Service Account JSON key, and add its client_email as Viewer in GA4 → Property Access Management.')
                        ->visible($isSuperAdmin),
                ])
                ->action(function (array $data) use ($isSuperAdmin): void {
                    $gaId = GoogleAnalyticsService::normalizeMeasurementId($data['google_analytics_id'] ?? '');
                    $gtmId = GoogleAnalyticsService::normalizeGtmId($data['gtm_container_id'] ?? '');

                    $toSave = [
                        'google_analytics_enabled' => ! empty($data['google_analytics_enabled']) ? '1' : '0',
                        'google_analytics_id' => $gaId,
                        'gtm_container_id' => $gtmId,
                    ];

                    if ($isSuperAdmin) {
                        $toSave['ga4_property_id'] = GoogleAnalyticsService::normalizePropertyId($data['ga4_property_id'] ?? '');
                        $rawJson = trim((string) ($data['ga4_service_account_json'] ?? ''));
                        if ($rawJson !== '') {
                            if (GoogleAnalyticsService::parseServiceAccount($rawJson) === null) {
                                Notification::make()
                                    ->title('Invalid Service Account JSON')
                                    ->body('Please paste the complete JSON key file containing client_email and private_key.')
                                    ->warning()
                                    ->persistent()
                                    ->send();

                                return;
                            }
                            $toSave['ga4_service_account_json'] = Setting::encryptSecret($rawJson);
                        }
                    }

                    Setting::setMany($toSave);
                    $this->forceRefresh = true;

                    Notification::make()
                        ->title('Google Analytics settings saved!')
                        ->body($gaId !== '' ? "Active Measurement ID: {$gaId}" : 'Storefront GA4 tag updated.')
                        ->success()
                        ->send();
                }),
        ];

        if ($isSuperAdmin) {
            $actions[] = Action::make('testGa4Api')
                ->label('Test Live API')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action(function (): void {
                    $res = app(GoogleAnalyticsService::class)->testConnection();
                    if ($res['success']) {
                        Notification::make()->title('GA4 Live API Connected!')->body($res['message'])->success()->send();
                    } else {
                        Notification::make()->title('GA4 API Notice')->body($res['message'])->warning()->persistent()->send();
                    }
                });
        }

        $actions[] = Action::make('refreshGa4')
            ->label('Refresh')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (): void {
                $this->forceRefresh = true;
                Notification::make()->title('Analytics refreshed')->success()->send();
            });

        $actions[] = Action::make('openGaExternal')
            ->label('Open GA4 Console')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url('https://analytics.google.com/', shouldOpenInNewTab: true);

        return $actions;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnalyticsViewData(): array
    {
        $enabled = (string) Setting::get('google_analytics_enabled', '1') === '1';
        $measurementId = GoogleAnalyticsService::normalizeMeasurementId((string) Setting::get('google_analytics_id', ''));
        $gtmId = GoogleAnalyticsService::normalizeGtmId((string) Setting::get('gtm_container_id', ''));
        $propertyId = GoogleAnalyticsService::normalizePropertyId((string) Setting::get('ga4_property_id', ''));

        $report = app(GoogleAnalyticsService::class)->fetchDashboardReport($this->preset, $this->forceRefresh);
        $this->forceRefresh = false;

        $days = match ($this->preset) {
            'today' => 0,
            '30d' => 29,
            '90d' => 89,
            default => 6,
        };
        $since = now()->subDays($days)->startOfDay();

        $paidOrdersQuery = Order::query()
            ->whereIn('status', [OrderStatus::Processing, OrderStatus::Completed, OrderStatus::NeedsAttention])
            ->whereRaw('COALESCE(paid_at, created_at) >= ?', [$since->toDateTimeString()]);

        $storeOrdersCount = (clone $paidOrdersQuery)->count();
        $storeRevenuePkr = round((float) (clone $paidOrdersQuery)->sum('total'), 0);

        return [
            'enabled' => $enabled,
            'measurement_id' => $measurementId,
            'gtm_id' => $gtmId,
            'property_id' => $propertyId,
            'report' => $report,
            'store_orders_count' => $storeOrdersCount,
            'store_revenue_pkr' => $storeRevenuePkr,
            'is_super_admin' => (bool) auth()->user()?->isSuperAdmin(),
        ];
    }
}
