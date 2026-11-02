<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailNotifier
{
    /** Prefix marking an encrypted SMTP password stored in the settings table. */
    public const ENCRYPTED_PREFIX = 'enc:';

    /** Mailer name used for store emails, kept separate from the .env default mailer. */
    private const MAILER = 'store_smtp';

    public static function encryptPassword(string $plain): string
    {
        return $plain === '' ? '' : self::ENCRYPTED_PREFIX.Crypt::encryptString($plain);
    }

    public static function decryptPassword(?string $stored): string
    {
        $stored = (string) $stored;

        if (! str_starts_with($stored, self::ENCRYPTED_PREFIX)) {
            return $stored; // legacy plain-text value
        }

        try {
            return Crypt::decryptString(substr($stored, strlen(self::ENCRYPTED_PREFIX)));
        } catch (\Throwable) {
            return '';
        }
    }

    public function isEnabled(): bool
    {
        return (string) Setting::get('mail_enabled', '1') === '1';
    }

    /**
     * Configure a dedicated SMTP mailer on-the-fly from database settings.
     *
     * Laravel 13 ignores the legacy "encryption" key, so the transport scheme
     * is derived explicitly: SSL => implicit TLS (smtps), TLS/None => smtp
     * (STARTTLS is negotiated automatically when the server offers it).
     *
     * @param  array<string, mixed>  $overrides  Unsaved form values (used by the admin test button).
     */
    public function configure(array $overrides = []): bool
    {
        $get = function (string $key, string $default = '') use ($overrides): string {
            if (array_key_exists($key, $overrides) && $overrides[$key] !== null && $overrides[$key] !== '') {
                return trim((string) $overrides[$key]);
            }

            return trim((string) Setting::get($key, $default));
        };

        $host = trim($get('mail_host'));
        $username = trim($get('mail_username'));
        $password = array_key_exists('mail_password', $overrides) && trim((string) $overrides['mail_password']) !== ''
            ? trim((string) $overrides['mail_password'])
            : trim(self::decryptPassword((string) Setting::get('mail_password', '')));

        if ($host === '' || $username === '') {
            return false;
        }

        $port = (int) ($get('mail_port', '465') ?: 465);
        $encryption = strtolower($get('mail_encryption', 'ssl'));

        $scheme = match (true) {
            $encryption === 'ssl' => 'smtps',
            $encryption === 'tls', $encryption === 'none' => 'smtp',
            default => $port === 465 ? 'smtps' : 'smtp',
        };

        $fromAddress = $get('mail_from_address') ?: $username;
        if (! filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $fromAddress = filter_var($username, FILTER_VALIDATE_EMAIL) ? $username : 'no-reply@'.(parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost');
        }
        $fromName = $get('mail_from_name') ?: (string) Setting::get('store_name', 'SellMeTools');

        config([
            'mail.mailers.'.self::MAILER => [
                'transport' => 'smtp',
                'scheme' => $scheme,
                'host' => $host,
                'port' => $port,
                'username' => $username,
                'password' => $password,
                'timeout' => 15,
                'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
            ],
            'mail.from.address' => $fromAddress,
            'mail.from.name' => $fromName,
        ]);

        // Drop any previously-resolved instance so new credentials apply immediately
        // (important for queue workers and repeated admin tests).
        Mail::purge(self::MAILER);

        return true;
    }

    /**
     * Email delivered credentials to the customer (when enabled in settings).
     * Sending happens after the HTTP response so customers never wait on SMTP.
     */
    public function sendOrderDelivered(Order $order): bool
    {
        if (! $this->isEnabled() || (string) Setting::get('mail_send_order_delivered', '1') !== '1') {
            return false;
        }

        if (blank($order->email) || ! filter_var($order->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (blank(Setting::get('mail_host')) || blank(Setting::get('mail_username'))) {
            return false;
        }

        $send = function () use ($order): void {
            $this->deliverOrderEmail($order);
        };

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            $send();
        } else {
            app()->terminating($send);
        }

        return true;
    }

    private function deliverOrderEmail(Order $order): bool
    {
        if (! $this->configure()) {
            return false;
        }

        try {
            $order->loadMissing('items.deliveries');
            $deliveredItems = $order->items->filter(fn ($item) => $item->deliveries->isNotEmpty());
            $storeName = (string) Setting::get('store_name', 'SellMeTools');

            Mail::mailer(self::MAILER)->send('emails.order-delivered', [
                'order' => $order,
                'deliveredItems' => $deliveredItems,
                'storeName' => $storeName,
                'orderUrl' => $order->customerUrl(),
            ], function ($message) use ($order, $storeName) {
                $message->to($order->email)
                    ->subject("Your order {$order->number} is delivered - {$storeName}");
                $this->applyReplyTo($message);
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('Failed to send order delivery email: '.$e->getMessage(), [
                'order' => $order->number,
                'email' => $order->email,
            ]);

            return false;
        }
    }

    /**
     * Send a password reset email to a user.
     */
    public function sendPasswordReset(User $user, string $token): bool
    {
        if (! $this->isEnabled() || ! $this->configure()) {
            return false;
        }

        try {
            $storeName = (string) Setting::get('store_name', 'SellMeTools');
            $resetUrl = route('password.reset', ['token' => $token, 'email' => $user->email]);

            Mail::mailer(self::MAILER)->send('emails.password-reset', [
                'user' => $user,
                'resetUrl' => $resetUrl,
                'storeName' => $storeName,
            ], function ($message) use ($user, $storeName) {
                $message->to($user->email)
                    ->subject("Reset your {$storeName} password");
                $this->applyReplyTo($message);
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('Failed to send password reset email: '.$e->getMessage(), [
                'email' => $user->email,
            ]);

            return false;
        }
    }

    /**
     * Send a test email to verify the SMTP connection.
     *
     * @param  array<string, mixed>  $overrides  Current (possibly unsaved) form values.
     * @return array{success: bool, message: string}
     */
    public function sendTest(string $recipientEmail, array $overrides = []): array
    {
        if (! $this->configure($overrides)) {
            return [
                'success' => false,
                'message' => 'SMTP Host or Username is missing. Please fill in the SMTP fields first.',
            ];
        }

        try {
            $storeName = (string) Setting::get('store_name', 'SellMeTools');
            $cfg = config('mail.mailers.'.self::MAILER);

            Mail::mailer(self::MAILER)->raw(
                "Assalam o Alaikum!\n\nThis is a test email from your {$storeName} store. Your SMTP configuration is working.\n\n".
                "Host: {$cfg['host']}:{$cfg['port']} ({$cfg['scheme']})\nSent at: ".now()->toDayDateTimeString(),
                function ($message) use ($recipientEmail, $storeName) {
                    $message->to($recipientEmail)->subject("SMTP test successful - {$storeName}");
                    $this->applyReplyTo($message);
                }
            );

            return [
                'success' => true,
                'message' => "Test email sent to {$recipientEmail}. Check your inbox (and the Spam folder).",
            ];
        } catch (\Throwable $e) {
            // If authentication (535) failed and password had hyphens or spaces (e.g. App Password "g55p-zgcn-ne10-ctpt"), retry once with hyphens/spaces stripped
            $currentPass = (string) (config('mail.mailers.'.self::MAILER.'.password') ?? '');
            $strippedPass = str_replace(['-', ' '], '', $currentPass);
            if ($strippedPass !== '' && $strippedPass !== $currentPass && str_contains(strtolower($e->getMessage()), '535')) {
                try {
                    $retryOverrides = array_merge($overrides, ['mail_password' => $strippedPass]);
                    $this->configure($retryOverrides);
                    $storeName = (string) Setting::get('store_name', 'SellMeTools');
                    $cfg = config('mail.mailers.'.self::MAILER);

                    Mail::mailer(self::MAILER)->raw(
                        "Assalam o Alaikum!\n\nThis is a test email from your {$storeName} store. Your SMTP configuration is working.\n\n".
                        "Host: {$cfg['host']}:{$cfg['port']} ({$cfg['scheme']})\nSent at: ".now()->toDayDateTimeString(),
                        function ($message) use ($recipientEmail, $storeName) {
                            $message->to($recipientEmail)->subject("SMTP test successful - {$storeName}");
                            $this->applyReplyTo($message);
                        }
                    );

                    Setting::set('mail_password', self::encryptPassword($strippedPass));

                    return [
                        'success' => true,
                        'message' => "Test email sent to {$recipientEmail}! (Auto-formatted App Password without dashes).",
                    ];
                } catch (\Throwable) {
                    // Fall through to original error report
                }
            }

            $cfg = config('mail.mailers.'.self::MAILER, []);
            $usedInfo = '[Used Host: '.($cfg['host'] ?? '?').':'.($cfg['port'] ?? '?').' | Username: '.($cfg['username'] ?? '?').'] ';

            return [
                'success' => false,
                'message' => $usedInfo.$this->explainError($e->getMessage()),
            ];
        }
    }

    /**
     * Send a customer inquiry from the Contact Us form to the store's support inbox via SMTP.
     *
     * @param  array{name: string, email: string, phone?: ?string, order_number?: ?string, subject: string, message: string}  $data
     */
    public function sendContactMessage(array $data): bool
    {
        $recipient = collect([
            Setting::get('support_email'),
            Setting::get('mail_from_address'),
            Setting::get('mail_username'),
        ])->map(fn ($v) => trim((string) $v))
            ->first(fn ($v) => $v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL));

        if (! $recipient || ! $this->isEnabled() || ! $this->configure()) {
            Log::info('Contact inquiry received (SMTP not configured):', $data);

            return false;
        }

        try {
            $storeName = (string) Setting::get('store_name', 'SellMeTools');

            Mail::mailer(self::MAILER)->send('emails.contact-message', [
                'inquiry' => $data,
                'storeName' => $storeName,
            ], function ($message) use ($recipient, $data, $storeName) {
                $subjectLine = "[{$storeName} Contact] {$data['subject']} - {$data['name']}";
                $message->to($recipient)->subject($subjectLine);
                if (filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                    $message->replyTo($data['email'], $data['name']);
                }
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('Failed to send contact form email: '.$e->getMessage(), [
                'email' => $data['email'] ?? null,
                'subject' => $data['subject'] ?? null,
            ]);

            return false;
        }
    }

    private function applyReplyTo($message): void
    {
        $support = trim((string) Setting::get('support_email', ''));
        if ($support !== '' && filter_var($support, FILTER_VALIDATE_EMAIL)) {
            $message->replyTo($support);
        }
    }

    /** Turn raw transport errors into actionable hints for the admin. */
    public function explainError(string $raw): string
    {
        $lower = strtolower($raw);

        $hint = match (true) {
            str_contains($lower, '535') || str_contains($lower, 'authentication') || str_contains($lower, 'username and password not accepted') => 'Login rejected. Check the username/password. For Gmail you must use a 16-character App Password (Google Account → Security → App passwords), not your normal password.',
            str_contains($lower, 'timed out') || str_contains($lower, 'timeout') => 'Connection timed out. Your host may block this port, try 587 with TLS, or 465 with SSL.',
            str_contains($lower, 'connection refused') || str_contains($lower, 'unable to connect') => 'Could not connect to the SMTP server. Check the host name and port, and that Encryption matches the port (465 = SSL, 587 = TLS).',
            str_contains($lower, 'ssl') || str_contains($lower, 'tls') || str_contains($lower, 'certificate') || str_contains($lower, 'wrong version number') => 'Encryption mismatch. Use SSL with port 465, or TLS with port 587.',
            str_contains($lower, 'getaddrinfo') || str_contains($lower, 'name or service not known') || str_contains($lower, 'no such host') => 'SMTP host name not found. Double-check the host (e.g. smtp.hostinger.com, smtp.gmail.com).',
            str_contains($lower, 'sender') || str_contains($lower, '553') || str_contains($lower, '550') => 'The server refused the sender address. Set "From Email" to the same address as the SMTP username.',
            default => null,
        };

        return $hint ? $hint.' (Server said: '.mb_strimwidth($raw, 0, 220, '…').')' : 'SMTP Error: '.mb_strimwidth($raw, 0, 300, '…');
    }
}
