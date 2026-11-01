<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\IncomingPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Handles automatic payment verification across:
 *  1. SMS / Notification Forwarder Webhook (Easypaisa, JazzCash, NayaPay, SadaPay, Bank SMS)
 *  2. Binance C2C / Pay History API (using read-only Binance API Key + Secret)
 *  3. PayFast / Paymob / Cryptomus Gateway Callbacks & Redirects
 */
class AutoPaymentVerifier
{
    public function __construct(
        protected PaymentService $payments,
    ) {}

    /**
     * Parses a raw SMS or notification message from Easypaisa / JazzCash / NayaPay / SadaPay
     * and extracts the Transaction ID (TID) and Amount in PKR.
     *
     * @return array{tid: string, amount: float, provider: string, sender: ?string}|null
     */
    public function parseSms(string $message, ?string $from = null): ?array
    {
        $text = trim($message);
        $lower = Str::lower($text.' '.($from ?? ''));

        $provider = match (true) {
            Str::contains($lower, ['easypaisa', '3737', 'telenor']) => 'easypaisa',
            Str::contains($lower, ['jazzcash', '8558', 'mobilink']) => 'jazzcash',
            Str::contains($lower, ['nayapay']) => 'nayapay',
            Str::contains($lower, ['sadapay']) => 'sadapay',
            Str::contains($lower, ['binance', 'usdt']) => 'binance',
            default => 'sms',
        };

        // Extract amount: e.g. "Rs 550.00", "Rs. 1,250", "PKR 550", "amount of Rs 550"
        $amount = null;
        if (preg_match('/(?:rs\.?|pkr|amount(?:\s+of)?\s+rs\.?)\s*([0-9,]+(?:\.[0-9]{1,2})?)/i', $text, $m)) {
            $amount = (float) str_replace(',', '', $m[1]);
        } elseif (preg_match('/([0-9,]+(?:\.[0-9]{1,2})?)\s*(?:pkr|rs|usdt)/i', $text, $m)) {
            $amount = (float) str_replace(',', '', $m[1]);
        }

        // Extract Transaction ID (TID / Trx ID / TxID / Ref / ID#):
        // Common formats: "Trx ID 12345678901", "TID: 0123456789", "TID 123456789012", "Ref# ABC12345"
        $tid = null;
        if (preg_match('/(?:trx\s*id|tid|trans(?:action)?\s*id|txid|order\s*id|ref(?:\s*no|\s*id|#)?)\s*[:#\-]?\s*([A-Z0-9\-]{6,32})/i', $text, $m)) {
            $tid = strtoupper(trim($m[1], ' .,-'));
        } elseif (preg_match('/\b(\d{10,14})\b/', $text, $m)) {
            $tid = $m[1];
        }

        if (! $tid || ! $amount || $amount <= 0) {
            return null;
        }

        $tid = PaymentService::normalizeTid($tid);
        if (strlen($tid) < 5) {
            return null;
        }

        $sender = null;
        if (preg_match('/\b(03\d{2}[\s\-]?\d{7})\b/', $text, $m)) {
            $sender = preg_replace('/\D/', '', $m[1]);
        }

        return [
            'tid' => $tid,
            'amount' => $amount,
            'provider' => $provider,
            'sender' => $sender,
        ];
    }

    /**
     * Records an incoming SMS/webhook payment and immediately approves any pending order
     * that already submitted this TID.
     */
    public function recordIncoming(string $tid, float $amount, string $provider = 'sms', string $currency = 'PKR', ?string $sender = null, ?string $raw = null): IncomingPayment
    {
        $tid = PaymentService::normalizeTid($tid);

        $incoming = IncomingPayment::updateOrCreate(
            ['provider' => $provider, 'transaction_id' => $tid],
            [
                'amount' => $amount,
                'currency' => strtoupper($currency),
                'sender' => $sender,
                'raw_message' => $raw,
            ]
        );

        if (! $incoming->matched_payment_id) {
            $pending = Payment::query()
                ->where('status', PaymentStatus::Pending)
                ->where('transaction_id', $tid)
                ->first();

            if ($pending && (float) $incoming->amount >= ((float) $pending->amount - 0.02)) {
                $this->matchAndApprove($incoming, $pending);
            }
        }

        return $incoming;
    }

    /**
     * Called right after a customer submits a Payment TID on the website.
     * Checks:
     *   1. Unmatched IncomingPayment table (from SMS forwarder / gateway webhook)
     *   2. Binance API (if payment method is Binance / USDT and API credentials are set)
     */
    public function tryAutoVerify(Payment $payment): bool
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return false;
        }

        $tid = PaymentService::normalizeTid($payment->transaction_id);

        // 1. Check incoming SMS / webhook payments (never match an already-used incoming record)
        $incoming = IncomingPayment::query()
            ->whereNull('matched_payment_id')
            ->where('transaction_id', $tid)
            ->first();

        if ($incoming && (float) $incoming->amount >= ((float) $payment->amount - 0.02)) {
            $this->matchAndApprove($incoming, $payment);

            return true;
        }

        // 2. Check Binance API if applicable (Binance Pay OR On-chain USDT deposit)
        $method = $payment->paymentMethod;
        if ($method && (in_array($method->code, ['binance', 'usdt_trc20'], true) || ($method->meta['gateway'] ?? '') === 'binance')) {
            if ($this->verifyViaBinanceApi($payment, $method)) {
                $this->payments->approve($payment);

                return true;
            }
        }

        return false;
    }

    protected function matchAndApprove(IncomingPayment $incoming, Payment $payment): void
    {
        $incoming->update([
            'matched_payment_id' => $payment->id,
            'matched_at' => now(),
        ]);

        $this->payments->approve($payment);
    }

    /**
     * Checks Binance Pay History (`/sapi/v1/pay/transactions`) AND On-Chain Crypto Deposit History
     * (`/sapi/v1/capital/deposit/hisrec`) using a Read-Only Binance API Key & Secret.
     * Locks matched transactions in `incoming_payments` so the same Binance Order ID / TxID can never be reused.
     */
    public function verifyViaBinanceApi(Payment $payment, PaymentMethod $method): bool
    {
        $apiKey = trim((string) ($method->meta['binance_api_key'] ?? ''));
        $apiSecret = trim((string) ($method->meta['binance_api_secret'] ?? ''));

        if ($apiKey === '' || $apiSecret === '') {
            return false;
        }

        $submittedTid = PaymentService::normalizeTid($payment->transaction_id);
        $requiredAmount = (float) $payment->amount - 0.02;

        // Ensure this Binance TID was never matched before
        if (IncomingPayment::query()->where('transaction_id', $submittedTid)->whereNotNull('matched_payment_id')->exists()) {
            return false;
        }

        try {
            // A) Check Binance Pay Transactions (/sapi/v1/pay/transactions)
            $timestamp = (int) round(microtime(true) * 1000);
            $query = "timestamp={$timestamp}&limit=50";
            $signature = hash_hmac('sha256', $query, $apiSecret);

            $response = Http::timeout(10)
                ->withHeaders(['X-MBX-APIKEY' => $apiKey])
                ->get("https://api.binance.com/sapi/v1/pay/transactions?{$query}&signature={$signature}");

            if ($response->successful()) {
                $rows = $response->json('data') ?? [];
                foreach ($rows as $row) {
                    $orderId = PaymentService::normalizeTid((string) ($row['orderId'] ?? ''));
                    $txId = PaymentService::normalizeTid((string) ($row['transactionId'] ?? ''));
                    $amount = (float) ($row['amount'] ?? 0);
                    $currency = strtoupper((string) ($row['currency'] ?? 'USDT'));

                    // Only incoming payments (amount > 0)
                    if (($orderId === $submittedTid || $txId === $submittedTid)
                        && $amount >= $requiredAmount
                        && $currency === strtoupper($payment->currency)) {
                        IncomingPayment::updateOrCreate(
                            ['provider' => 'binance_pay', 'transaction_id' => $submittedTid],
                            [
                                'amount' => $amount,
                                'currency' => $currency,
                                'matched_payment_id' => $payment->id,
                                'matched_at' => now(),
                                'raw_message' => json_encode($row),
                            ]
                        );

                        return true;
                    }
                }
            }

            // B) Check Binance On-Chain Deposit History (/sapi/v1/capital/deposit/hisrec) for USDT TRC20/BEP20
            $ts2 = (int) round(microtime(true) * 1000);
            $query2 = "timestamp={$ts2}&status=1&limit=50";
            $sig2 = hash_hmac('sha256', $query2, $apiSecret);

            $depResponse = Http::timeout(10)
                ->withHeaders(['X-MBX-APIKEY' => $apiKey])
                ->get("https://api.binance.com/sapi/v1/capital/deposit/hisrec?{$query2}&signature={$sig2}");

            if ($depResponse->successful()) {
                $deposits = $depResponse->json() ?? [];
                if (is_array($deposits)) {
                    foreach ($deposits as $dep) {
                        $txId = PaymentService::normalizeTid((string) ($dep['txId'] ?? ''));
                        $depId = PaymentService::normalizeTid((string) ($dep['id'] ?? ''));
                        $amount = (float) ($dep['amount'] ?? 0);
                        $coin = strtoupper((string) ($dep['coin'] ?? 'USDT'));

                        if (($txId === $submittedTid || $depId === $submittedTid)
                            && $amount >= $requiredAmount
                            && $coin === strtoupper($payment->currency)) {
                            IncomingPayment::updateOrCreate(
                                ['provider' => 'binance_deposit', 'transaction_id' => $submittedTid],
                                [
                                    'amount' => $amount,
                                    'currency' => $coin,
                                    'matched_payment_id' => $payment->id,
                                    'matched_at' => now(),
                                    'raw_message' => json_encode($dep),
                                ]
                            );

                            return true;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('Binance auto-verify error: '.$e->getMessage());
        }

        return false;
    }

    /**
     * Builds an online checkout redirect URL for gateways like Cryptomus or PayFast
     * when configured in the PaymentMethod's meta.
     */
    public function gatewayCheckoutUrl(Order $order, PaymentMethod $method): ?string
    {
        $gateway = $method->meta['gateway'] ?? 'manual';

        if ($gateway === 'custom_link' && ! empty($method->meta['checkout_url'])) {
            return str_replace(
                ['{order}', '{amount}', '{currency}'],
                [urlencode($order->number), urlencode((string) $method->amountFor($order)), urlencode($method->currency)],
                (string) $method->meta['checkout_url']
            );
        }

        return null;
    }
}
