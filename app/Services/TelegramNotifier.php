<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends Admin order & payment notifications to Telegram with 1-Tap In-Chat Approve & Decline buttons.
 */
class TelegramNotifier
{
    public ?string $lastError = null;

    public static function cleanToken(string $token): string
    {
        $token = trim($token);
        if (preg_match('/(\d{6,15}:[A-Za-z0-9_\-]{20,})/', $token, $m)) {
            return $m[1];
        }

        return preg_replace('/^bot/i', '', $token) ?? $token;
    }

    public function enabled(): bool
    {
        return filled(Setting::get('telegram_bot_token')) && filled(Setting::get('telegram_admin_chat_id'));
    }

    public function canonicalWebhookUrl(): string
    {
        $rawUrl = url('/api/webhooks/telegram');
        if (str_contains($rawUrl, 'hostingersite.com')) {
            return 'https://sellmetools.com/api/webhooks/telegram';
        }

        return $rawUrl;
    }

    public function notifyAdmin(string $html, ?array $inlineKeyboard = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        $token = self::cleanToken((string) Setting::get('telegram_bot_token'));
        $chatId = trim((string) Setting::get('telegram_admin_chat_id'));

        $this->send($token, $chatId, $html, $inlineKeyboard);
    }

    /** @return bool true when Telegram accepted the message */
    public function send(string $token, string $chatId, string $html, ?array $inlineKeyboard = null): bool
    {
        $this->lastError = null;
        $token = self::cleanToken($token);
        $chatId = trim($chatId);

        if ($inlineKeyboard && ! app()->runningUnitTests()) {
            $webhookUrl = $this->canonicalWebhookUrl();
            $cacheKey = 'tg_webhook_registered:'.md5($token.'|'.$webhookUrl);
            if (! Cache::has($cacheKey)) {
                Cache::put($cacheKey, true, now()->addDays(7));
                $this->registerWebhook($token);
            }
        }

        try {
            $payload = [
                'chat_id' => $chatId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ];

            if ($inlineKeyboard) {
                $payload['reply_markup'] = ['inline_keyboard' => $inlineKeyboard];
            }

            $response = Http::timeout(8)->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);

            if (! $response->successful() || $response->json('ok') === false) {
                $desc = (string) ($response->json('description') ?? ('HTTP '.$response->status()));
                $this->lastError = $desc;
                Log::warning('Telegram send failed', ['status' => $response->status(), 'body' => $response->body()]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::warning('Telegram send exception: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Automatically detects the Admin's numeric Chat ID from the latest `/start` or text message
     * sent to the bot.
     */
    public function detectChatId(string $token): ?string
    {
        $token = self::cleanToken($token);
        if ($token === '') {
            return null;
        }

        try {
            Http::timeout(6)->post("https://api.telegram.org/bot{$token}/deleteWebhook", [
                'drop_pending_updates' => false,
            ]);

            $response = Http::timeout(8)->get("https://api.telegram.org/bot{$token}/getUpdates", [
                'limit' => 20,
            ]);

            $this->registerWebhook($token);

            if (! $response->successful()) {
                $this->lastError = (string) ($response->json('description') ?? ('HTTP '.$response->status()));

                return null;
            }

            $updates = $response->json('result') ?? [];
            if (! is_array($updates) || $updates === []) {
                return null;
            }

            for ($i = count($updates) - 1; $i >= 0; $i--) {
                $u = $updates[$i];
                $id = $u['message']['chat']['id']
                    ?? $u['edited_message']['chat']['id']
                    ?? $u['callback_query']['message']['chat']['id']
                    ?? $u['my_chat_member']['chat']['id']
                    ?? null;

                if ($id !== null) {
                    return (string) $id;
                }
            }
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
        }

        return null;
    }

    public function registerWebhook(string $token): bool
    {
        $token = self::cleanToken($token);
        if ($token === '') {
            return false;
        }

        $webhookUrl = $this->canonicalWebhookUrl();
        if (! Str::startsWith($webhookUrl, 'https://')) {
            return false;
        }

        try {
            $response = Http::timeout(6)->post("https://api.telegram.org/bot{$token}/setWebhook", [
                'url' => $webhookUrl,
                'allowed_updates' => ['callback_query'],
                'drop_pending_updates' => true,
            ]);

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('Telegram setWebhook error: '.$e->getMessage());

            return false;
        }
    }

    public function answerCallback(string $callbackQueryId, string $text, bool $alert = true): void
    {
        $token = self::cleanToken((string) Setting::get('telegram_bot_token'));
        if ($token === '') {
            return;
        }

        try {
            Http::timeout(6)->post("https://api.telegram.org/bot{$token}/answerCallbackQuery", [
                'callback_query_id' => $callbackQueryId,
                'text' => Str::limit($text, 190),
                'show_alert' => $alert,
            ]);
        } catch (Throwable) {
        }
    }

    public function editMessage(string $chatId, int $messageId, string $html): void
    {
        $token = self::cleanToken((string) Setting::get('telegram_bot_token'));
        if ($token === '') {
            return;
        }

        try {
            Http::timeout(6)->post("https://api.telegram.org/bot{$token}/editMessageText", [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (Throwable) {
        }
    }

    public static function e(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
