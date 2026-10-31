<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends admin alerts to Telegram. Never throws – notifications must not break business flows.
 * In web requests the message is sent after the response is flushed to the browser.
 */
class TelegramNotifier
{
    public function enabled(): bool
    {
        return filled(Setting::get('telegram_bot_token')) && filled(Setting::get('telegram_admin_chat_id'));
    }

    public function notifyAdmin(string $html): void
    {
        if (! $this->enabled()) {
            return;
        }

        $token = Setting::get('telegram_bot_token');
        $chatId = Setting::get('telegram_admin_chat_id');

        if (app()->runningInConsole() || app()->runningUnitTests()) {
            $this->send($token, $chatId, $html);

            return;
        }

        dispatch(fn () => $this->send($token, $chatId, $html))->afterResponse();
    }

    /** @return bool true when Telegram accepted the message */
    public function send(string $token, string $chatId, string $html): bool
    {
        try {
            $response = Http::timeout(8)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            if (! $response->successful()) {
                Log::warning('Telegram send failed', ['status' => $response->status(), 'body' => $response->body()]);
            }

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('Telegram send exception: '.$e->getMessage());

            return false;
        }
    }

    public static function e(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
