<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class GoogleAnalyticsService
{
    private const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const ANALYTICS_SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    private const DATA_API_BASE = 'https://analyticsdata.googleapis.com/v1beta/properties/';

    /**
     * Extracts a clean GA4 / Google Tag Measurement ID (e.g. G-XXXXXXXXXX) even if the user pasted a full <script> snippet.
     */
    public static function normalizeMeasurementId(?string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/\b(G-[A-Z0-9]{4,20}|GT-[A-Z0-9]{4,20}|UA-\d{4,12}-\d{1,4})\b/i', $raw, $m)) {
            return strtoupper($m[1]);
        }

        return strtoupper((string) preg_replace('/[^A-Za-z0-9\-]/', '', $raw));
    }

    /**
     * Extracts a clean Google Tag Manager Container ID (e.g. GTM-XXXXXXX) even from a pasted <script> snippet.
     */
    public static function normalizeGtmId(?string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/\b(GTM-[A-Z0-9]{4,16})\b/i', $raw, $m)) {
            return strtoupper($m[1]);
        }

        return '';
    }

    /**
     * Normalizes a GA4 Property ID (e.g. "properties/412345678" -> "412345678").
     */
    public static function normalizePropertyId(?string $raw): string
    {
        $raw = trim((string) $raw);
        $raw = (string) preg_replace('/^properties\//i', '', $raw);

        return (string) preg_replace('/\D+/', '', $raw);
    }

    /**
     * @return array{client_email: string, private_key: string}|null
     */
    public static function parseServiceAccount(?string $rawJson): ?array
    {
        $rawJson = trim((string) $rawJson);
        if ($rawJson === '') {
            return null;
        }

        $decoded = json_decode($rawJson, true);
        if (! is_array($decoded)) {
            return null;
        }

        $email = trim((string) ($decoded['client_email'] ?? ''));
        $privateKey = str_replace('\\n', "\n", trim((string) ($decoded['private_key'] ?? '')));

        if ($email === '' || $privateKey === '' || ! str_contains($privateKey, 'PRIVATE KEY')) {
            return null;
        }

        return [
            'client_email' => $email,
            'private_key' => $privateKey,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $overrides
     */
    public function isReportingConfigured(?array $overrides = null): bool
    {
        $propertyId = self::normalizePropertyId((string) ($overrides['ga4_property_id'] ?? Setting::get('ga4_property_id', '')));
        $rawJson = array_key_exists('ga4_service_account_json', $overrides ?? []) && trim((string) $overrides['ga4_service_account_json']) !== ''
            ? (string) $overrides['ga4_service_account_json']
            : Setting::decryptSecret((string) Setting::get('ga4_service_account_json', ''));

        return $propertyId !== '' && self::parseServiceAccount($rawJson) !== null;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $overrides = []): array
    {
        $propertyId = self::normalizePropertyId((string) ($overrides['ga4_property_id'] ?? Setting::get('ga4_property_id', '')));
        if ($propertyId === '') {
            return [
                'success' => false,
                'message' => 'Enter your numeric GA4 Property ID first (found in Google Analytics → Admin → Property Details, e.g. 412345678).',
            ];
        }

        $rawJson = array_key_exists('ga4_service_account_json', $overrides) && trim((string) $overrides['ga4_service_account_json']) !== ''
            ? (string) $overrides['ga4_service_account_json']
            : Setting::decryptSecret((string) Setting::get('ga4_service_account_json', ''));

        $sa = self::parseServiceAccount($rawJson);
        if ($sa === null) {
            return [
                'success' => false,
                'message' => 'Invalid or missing Service Account JSON. Paste the full JSON key file containing client_email and private_key.',
            ];
        }

        $tokenRes = $this->mintAccessToken($sa, true);
        if (! $tokenRes['ok']) {
            return [
                'success' => false,
                'message' => 'Google OAuth failed: '.($tokenRes['error'] ?? 'Unknown error'),
            ];
        }

        try {
            $resp = Http::timeout(12)
                ->withToken((string) $tokenRes['token'])
                ->post(self::DATA_API_BASE.$propertyId.':runRealtimeReport', [
                    'metrics' => [['name' => 'activeUsers']],
                ]);

            if ($resp->successful()) {
                $active = (int) data_get($resp->json(), 'rows.0.metricValues.0.value', 0);

                return [
                    'success' => true,
                    'message' => "GA4 Property {$propertyId} connected! Realtime active visitors right now: {$active} (Service account: {$sa['client_email']}).",
                ];
            }

            $errMsg = (string) (data_get($resp->json(), 'error.message') ?: ('HTTP '.$resp->status()));
            if ($resp->status() === 403) {
                $errMsg .= " — Add {$sa['client_email']} as a Viewer in Google Analytics → Admin → Property Access Management (and enable Google Analytics Data API in Google Cloud).";
            }

            return [
                'success' => false,
                'message' => $errMsg,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Connection error: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Fetches live GA4 overview, daily chart, top pages, traffic sources, and device split.
     *
     * @return array<string, mixed>
     */
    public function fetchDashboardReport(string $preset = '7d', bool $forceRefresh = false): array
    {
        $propertyId = self::normalizePropertyId((string) Setting::get('ga4_property_id', ''));
        $rawJson = Setting::decryptSecret((string) Setting::get('ga4_service_account_json', ''));
        $sa = self::parseServiceAccount($rawJson);

        if ($propertyId === '' || $sa === null) {
            return [
                'configured' => false,
                'ok' => false,
                'error' => null,
            ];
        }

        $cacheKey = "smt_ga4_report_{$propertyId}_{$preset}_v1";
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        $fetcher = fn (): array => $this->executeBatchReport($propertyId, $sa, $preset);

        if (app()->runningUnitTests()) {
            return $fetcher();
        }

        return Cache::remember($cacheKey, 300, $fetcher);
    }

    /**
     * @param  array{client_email: string, private_key: string}  $sa
     * @return array<string, mixed>
     */
    protected function executeBatchReport(string $propertyId, array $sa, string $preset): array
    {
        $tokenRes = $this->mintAccessToken($sa);
        if (! $tokenRes['ok']) {
            return [
                'configured' => true,
                'ok' => false,
                'error' => $tokenRes['error'] ?? 'Failed to authenticate with Google OAuth.',
                'service_account_email' => $sa['client_email'],
                'property_id' => $propertyId,
            ];
        }

        [$startDate, $endDate, $periodLabel] = match ($preset) {
            'today' => ['today', 'today', 'Today'],
            '30d' => ['29daysAgo', 'today', 'Last 30 Days'],
            '90d' => ['89daysAgo', 'today', 'Last 90 Days'],
            default => ['6daysAgo', 'today', 'Last 7 Days'],
        };

        $dateRanges = [['startDate' => $startDate, 'endDate' => $endDate]];
        $token = (string) $tokenRes['token'];

        try {
            $realtimeUsers = 0;
            $rtResp = Http::timeout(10)
                ->withToken($token)
                ->post(self::DATA_API_BASE.$propertyId.':runRealtimeReport', [
                    'metrics' => [['name' => 'activeUsers']],
                ]);
            if ($rtResp->successful()) {
                $realtimeUsers = (int) data_get($rtResp->json(), 'rows.0.metricValues.0.value', 0);
            }

            $batchResp = Http::timeout(15)
                ->withToken($token)
                ->post(self::DATA_API_BASE.$propertyId.':batchRunReports', [
                    'requests' => [
                        // 0: Overview Totals
                        [
                            'dateRanges' => $dateRanges,
                            'metrics' => [
                                ['name' => 'activeUsers'],
                                ['name' => 'newUsers'],
                                ['name' => 'sessions'],
                                ['name' => 'screenPageViews'],
                                ['name' => 'engagedSessions'],
                                ['name' => 'ecommercePurchases'],
                                ['name' => 'purchaseRevenue'],
                            ],
                        ],
                        // 1: Daily Trend
                        [
                            'dateRanges' => $dateRanges,
                            'dimensions' => [['name' => 'date']],
                            'metrics' => [
                                ['name' => 'activeUsers'],
                                ['name' => 'screenPageViews'],
                            ],
                            'orderBys' => [
                                ['dimension' => ['dimensionName' => 'date'], 'desc' => false],
                            ],
                        ],
                        // 2: Top Pages
                        [
                            'dateRanges' => $dateRanges,
                            'dimensions' => [
                                ['name' => 'pagePath'],
                                ['name' => 'pageTitle'],
                            ],
                            'metrics' => [
                                ['name' => 'screenPageViews'],
                                ['name' => 'activeUsers'],
                            ],
                            'limit' => 10,
                        ],
                        // 3: Traffic Sources / Channels
                        [
                            'dateRanges' => $dateRanges,
                            'dimensions' => [['name' => 'sessionSourceMedium']],
                            'metrics' => [
                                ['name' => 'sessions'],
                                ['name' => 'activeUsers'],
                            ],
                            'limit' => 8,
                        ],
                        // 4: Device Breakdown
                        [
                            'dateRanges' => $dateRanges,
                            'dimensions' => [['name' => 'deviceCategory']],
                            'metrics' => [['name' => 'activeUsers']],
                            'limit' => 5,
                        ],
                    ],
                ]);

            if (! $batchResp->successful()) {
                $err = (string) (data_get($batchResp->json(), 'error.message') ?: ('GA4 HTTP '.$batchResp->status()));

                return [
                    'configured' => true,
                    'ok' => false,
                    'error' => $err,
                    'service_account_email' => $sa['client_email'],
                    'property_id' => $propertyId,
                ];
            }

            $reports = $batchResp->json('reports') ?? [];

            $ovVals = data_get($reports, '0.rows.0.metricValues', []);
            $activeUsers = (int) ($ovVals[0]['value'] ?? 0);
            $newUsers = (int) ($ovVals[1]['value'] ?? 0);
            $sessions = (int) ($ovVals[2]['value'] ?? 0);
            $pageViews = (int) ($ovVals[3]['value'] ?? 0);
            $engagedSessions = (int) ($ovVals[4]['value'] ?? 0);
            $purchases = (int) ($ovVals[5]['value'] ?? 0);
            $revenue = round((float) ($ovVals[6]['value'] ?? 0), 2);
            $engagementRate = $sessions > 0 ? round(($engagedSessions / $sessions) * 100, 1) : 0.0;

            $daily = [];
            $maxViews = 1;
            foreach ((array) data_get($reports, '1.rows', []) as $row) {
                $rawDate = (string) data_get($row, 'dimensionValues.0.value', '');
                $label = strlen($rawDate) === 8
                    ? substr($rawDate, 6, 2).'/'.substr($rawDate, 4, 2)
                    : $rawDate;
                $u = (int) data_get($row, 'metricValues.0.value', 0);
                $pv = (int) data_get($row, 'metricValues.1.value', 0);
                $maxViews = max($maxViews, $pv, $u);
                $daily[] = [
                    'date' => $label,
                    'users' => $u,
                    'views' => $pv,
                ];
            }
            foreach ($daily as $idx => $d) {
                $daily[$idx]['height_pct'] = max(6, (int) round(($d['views'] / $maxViews) * 100));
            }

            $topPages = [];
            foreach ((array) data_get($reports, '2.rows', []) as $row) {
                $topPages[] = [
                    'path' => (string) data_get($row, 'dimensionValues.0.value', '/'),
                    'title' => (string) data_get($row, 'dimensionValues.1.value', ''),
                    'views' => (int) data_get($row, 'metricValues.0.value', 0),
                    'users' => (int) data_get($row, 'metricValues.1.value', 0),
                ];
            }

            $sources = [];
            $totalSourceSessions = 0;
            foreach ((array) data_get($reports, '3.rows', []) as $row) {
                $sess = (int) data_get($row, 'metricValues.0.value', 0);
                $totalSourceSessions += $sess;
                $sources[] = [
                    'source' => (string) data_get($row, 'dimensionValues.0.value', '(direct) / (none)'),
                    'sessions' => $sess,
                    'users' => (int) data_get($row, 'metricValues.1.value', 0),
                ];
            }
            foreach ($sources as $idx => $s) {
                $sources[$idx]['share_pct'] = $totalSourceSessions > 0
                    ? round(($s['sessions'] / $totalSourceSessions) * 100, 1)
                    : 0.0;
            }

            $devices = [];
            $totalDeviceUsers = 0;
            foreach ((array) data_get($reports, '4.rows', []) as $row) {
                $u = (int) data_get($row, 'metricValues.0.value', 0);
                $totalDeviceUsers += $u;
                $devices[] = [
                    'device' => ucfirst((string) data_get($row, 'dimensionValues.0.value', 'unknown')),
                    'users' => $u,
                ];
            }
            foreach ($devices as $idx => $dv) {
                $devices[$idx]['share_pct'] = $totalDeviceUsers > 0
                    ? round(($dv['users'] / $totalDeviceUsers) * 100, 1)
                    : 0.0;
            }

            return [
                'configured' => true,
                'ok' => true,
                'error' => null,
                'property_id' => $propertyId,
                'service_account_email' => $sa['client_email'],
                'period_label' => $periodLabel,
                'realtime_users' => $realtimeUsers,
                'active_users' => $activeUsers,
                'new_users' => $newUsers,
                'sessions' => $sessions,
                'page_views' => $pageViews,
                'engagement_rate' => $engagementRate,
                'purchases' => $purchases,
                'revenue' => $revenue,
                'daily' => $daily,
                'top_pages' => $topPages,
                'sources' => $sources,
                'devices' => $devices,
            ];
        } catch (Throwable $e) {
            return [
                'configured' => true,
                'ok' => false,
                'error' => $e->getMessage(),
                'service_account_email' => $sa['client_email'],
                'property_id' => $propertyId,
            ];
        }
    }

    /**
     * Mints an OAuth2 Bearer token from a Google Service Account using RS256 JWT.
     *
     * @param  array{client_email: string, private_key: string}  $sa
     * @return array{ok: bool, token?: string, error?: string}
     */
    protected function mintAccessToken(array $sa, bool $bypassCache = false): array
    {
        $cacheKey = 'smt_ga4_oauth_token_'.md5($sa['client_email']);
        if (! $bypassCache && ! app()->runningUnitTests()) {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return ['ok' => true, 'token' => $cached];
            }
        }

        $now = time();
        $header = self::base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::base64UrlEncode((string) json_encode([
            'iss' => $sa['client_email'],
            'scope' => self::ANALYTICS_SCOPE,
            'aud' => self::OAUTH_TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signingInput = "{$header}.{$claims}";
        $signature = '';

        try {
            $signed = @openssl_sign($signingInput, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Private key signing error: '.$e->getMessage()];
        }

        if (! $signed) {
            return ['ok' => false, 'error' => 'Could not sign JWT with the provided Service Account private_key.'];
        }

        $jwt = $signingInput.'.'.self::base64UrlEncode($signature);

        try {
            $resp = Http::timeout(10)
                ->asForm()
                ->post(self::OAUTH_TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

            if (! $resp->successful()) {
                $err = (string) ($resp->json('error_description') ?: $resp->json('error') ?: ('HTTP '.$resp->status()));

                return ['ok' => false, 'error' => $err];
            }

            $accessToken = trim((string) $resp->json('access_token', ''));
            if ($accessToken === '') {
                return ['ok' => false, 'error' => 'Empty access_token returned by Google OAuth.'];
            }

            if (! app()->runningUnitTests()) {
                Cache::put($cacheKey, $accessToken, 3000);
            }

            return ['ok' => true, 'token' => $accessToken];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
