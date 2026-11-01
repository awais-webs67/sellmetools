<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailNotifier
{
    /**
     * Configure Laravel's SMTP mailer on-the-fly using database settings.
     */
    public function configure(): bool
    {
        $host = trim((string) Setting::get('mail_host', ''));
        $username = trim((string) Setting::get('mail_username', ''));
        $password = (string) Setting::get('mail_password', '');

        if ($host === '' || $username === '') {
            return false;
        }

        $port = (int) Setting::get('mail_port', 465);
        $encryption = strtolower(trim((string) Setting::get('mail_encryption', 'ssl')));
        if ($encryption === 'none' || $encryption === '') {
            $encryption = null;
        }

        $fromAddress = trim((string) Setting::get('mail_from_address', ''));
        if ($fromAddress === '') {
            $fromAddress = $username;
        }

        $fromName = trim((string) Setting::get('mail_from_name', ''));
        if ($fromName === '') {
            $fromName = (string) Setting::get('store_name', 'SellMeTools');
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => $port,
            'mail.mailers.smtp.encryption' => $encryption,
            'mail.mailers.smtp.username' => $username,
            'mail.mailers.smtp.password' => $password,
            'mail.mailers.smtp.timeout' => 12,
            'mail.from.address' => $fromAddress,
            'mail.from.name' => $fromName,
        ]);

        return true;
    }

    /**
     * Send order credentials to the customer if SMTP and order delivery emails are enabled.
     */
    public function sendOrderDelivered(Order $order): bool
    {
        $enabled = (string) Setting::get('mail_enabled', '1');
        $sendDelivery = (string) Setting::get('mail_send_order_delivered', '1');

        if ($enabled !== '1' || $sendDelivery !== '1') {
            return false;
        }

        if (blank($order->email) || ! filter_var($order->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (! $this->configure()) {
            return false;
        }

        try {
            $deliveredItems = $order->items->filter(fn ($item) => $item->deliveries->isNotEmpty());
            $storeName = (string) Setting::get('store_name', 'SellMeTools');

            Mail::send('emails.order-delivered', [
                'order' => $order,
                'deliveredItems' => $deliveredItems,
                'storeName' => $storeName,
                'orderUrl' => $order->customerUrl(),
            ], function ($message) use ($order, $storeName) {
                $message->to($order->email)
                    ->subject("🎉 Order {$order->number} Confirmed & Delivered – {$storeName}");
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
        if (! $this->configure()) {
            return false;
        }

        try {
            $storeName = (string) Setting::get('store_name', 'SellMeTools');
            $resetUrl = route('password.reset', ['token' => $token, 'email' => $user->email]);

            Mail::send('emails.password-reset', [
                'user' => $user,
                'resetUrl' => $resetUrl,
                'storeName' => $storeName,
            ], function ($message) use ($user, $storeName) {
                $message->to($user->email)
                    ->subject("Reset Your Password – {$storeName}");
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
     * Send a test email to verify SMTP connection.
     *
     * @return array{success: bool, message: string}
     */
    public function sendTest(string $recipientEmail): array
    {
        if (! $this->configure()) {
            return [
                'success' => false,
                'message' => 'SMTP Host or Username is missing. Please fill in the SMTP fields first.',
            ];
        }

        try {
            $storeName = (string) Setting::get('store_name', 'SellMeTools');

            Mail::raw("Assalam o Alaikum!\n\nThis is a test email from your {$storeName} store. Your SMTP configuration is working perfectly!\n\nTimestamp: ".now()->toDayDateTimeString(), function ($message) use ($recipientEmail, $storeName) {
                $message->to($recipientEmail)
                    ->subject("✅ SMTP Test Successful – {$storeName}");
            });

            return [
                'success' => true,
                'message' => "Test email sent successfully to {$recipientEmail}!",
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'SMTP Error: '.$e->getMessage(),
            ];
        }
    }
}
