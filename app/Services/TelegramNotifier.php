<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends admin alerts to Telegram with 1-Tap Approve & Decline buttons.
 * Supports both in-chat callback buttons (via webhook) and signed 1-tap web links.
 */
class TelegramNotifier
{
    public function enabled(): bool
    {
        return filled(Setting::get('telegram_bot_token')) && filled(Setting::get('telegram_admin_chat_id'));
    }

    public function notifyAdmin(string $html, ?array $inlineKeyboard = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        $token = (string) Setting::get('telegram_bot_token');
        $chatId = (string) Setting::get('telegram_admin_chat_id');

        if (app()->runningInConsole() || app()->runningUnitTests()) {
            $this->send($token, $chatId, $html, $inlineKeyboard);

            return;
        }

        dispatch(fn () => $this->send($token, $chatId, $html, $inlineKeyboard))->afterResponse();
    }

    /** @return bool true when Telegram accepted the message */
    public function send(string $token, string $chatId, string $html, ?array $inlineKeyboard = null): bool
    {
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

            if (! $response->successful()) {
                Log::warning('Telegram send failed', ['status' => $response->status(), 'body' => $response->body()]);
            }

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('Telegram send exception: '.$e->getMessage());

            return false;
        }
    }

    public function registerWebhook(string $token): bool
    {
        $webhookUrl = url('/api/webhooks/telegram');
        if (! Str::startsWith($webhookUrl, 'https://')) {
            return false;
        }

        try {
            $secret = substr(hash('sha256', (string) Setting::get('sms_webhook_secret', 'smt_tg_secret')), 0, 32);
            $response = Http::timeout(8)->post("https://api.telegram.org/bot{$token}/setWebhook", [
                'url' => $webhookUrl,
                'secret_token' => $secret,
                'allowed_updates' => ['callback_query', 'message'],
            ]);

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('Telegram setWebhook error: '.$e->getMessage());

            return false;
        }
    }

    public function answerCallback(string $callbackQueryId, string $text, bool $alert = true): void
    {
        $token = (string) Setting::get('telegram_bot_token');
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
        $token = (string) Setting::get('telegram_bot_token');
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
