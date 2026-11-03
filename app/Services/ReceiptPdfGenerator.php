<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;

/**
 * Lightweight, zero-dependency, on-demand PDF 1.4 Receipt & Invoice Generator.
 * Runs ONLY when the customer clicks "Download PDF Receipt" — zero background CPU or disk load.
 */
class ReceiptPdfGenerator
{
    public function generate(Order $order): string
    {
        $order->loadMissing(['items.deliveries', 'paymentMethod', 'latestPayment']);

        $storeName = $this->clean((string) Setting::get('store_name', 'SellMeTools'));
        $orderNumber = $this->clean($order->number);
        $statusLabel = $this->clean(strtoupper($order->status->label()));
        $placedAt = $this->clean($order->created_at?->format('d M Y, h:i A') ?? now()->format('d M Y, h:i A'));
        $paidAt = $this->clean($order->paid_at?->format('d M Y, h:i A') ?? ($order->completed_at?->format('d M Y, h:i A') ?? '-'));
        $customerName = $this->clean($order->customer_name ?: 'Valued Customer');
        $customerEmail = $this->clean($order->email);
        $customerPhone = $this->clean($order->phone ?: ($order->latestPayment?->sender_number ?: '-'));
        $gatewayName = $this->clean($order->paymentMethod?->name ?: 'Instant / Free');
        $tid = $this->clean($order->latestPayment?->transaction_id ?: 'N/A');
        $totalPkr = 'PKR '.number_format((float) $order->total, 2);
        $rate = max(1.0, (float) ($order->usdt_rate ?: Setting::usdtRate()));
        $totalUsd = '$'.number_format((float) $order->total / $rate, 2).' USDT';

        $ops = [];

        // Top Dark Brand Header Box (x=40, y=730, w=515, h=72)
        $ops[] = '0.08 0.09 0.10 rg';
        $ops[] = '40 730 515 72 re f';

        // Brand Name & Receipt Title in White
        $ops[] = $this->text(56, 774, $storeName, 'F2', 20, [1, 1, 1]);
        $ops[] = $this->text(56, 756, 'A Project by AivraSol Softwares (https://aivrasol.com)', 'F1', 9, [0.75, 0.78, 0.82]);
        $ops[] = $this->text(56, 742, 'Global AI Tools & Digital Subscriptions Marketplace - https://sellmetools.com', 'F1', 8.5, [0.65, 0.68, 0.72]);

        $ops[] = $this->text(395, 776, 'OFFICIAL RECEIPT', 'F2', 14, [0.29, 0.87, 0.50]);
        $ops[] = $this->text(395, 758, 'Order #: '.$orderNumber, 'F2', 10.5, [1, 1, 1]);
        $ops[] = $this->text(395, 743, 'Status: '.$statusLabel, 'F1', 9, [0.85, 0.88, 0.92]);

        // Info Cards Background (x=40, y=635, w=515, h=82)
        $ops[] = '0.97 0.98 0.99 rg';
        $ops[] = '40 635 515 82 re f';
        $ops[] = '0.88 0.90 0.92 RG 0.8 w';
        $ops[] = '40 635 515 82 re S';

        // Left Column: Billed To
        $ops[] = $this->text(54, 700, 'BILLED TO CUSTOMER', 'F2', 8.5, [0.43, 0.46, 0.50]);
        $ops[] = $this->text(54, 684, 'Name: '.$customerName, 'F2', 10, [0.08, 0.09, 0.10]);
        $ops[] = $this->text(54, 669, 'Email: '.$customerEmail, 'F1', 9.5, [0.20, 0.23, 0.26]);
        $ops[] = $this->text(54, 654, 'WhatsApp / Phone: '.$customerPhone, 'F1', 9.5, [0.20, 0.23, 0.26]);

        // Right Column: Payment Info
        $ops[] = $this->text(310, 700, 'PAYMENT & VERIFICATION', 'F2', 8.5, [0.43, 0.46, 0.50]);
        $ops[] = $this->text(310, 684, 'Method: '.mb_strimwidth($gatewayName, 0, 36, '...'), 'F2', 9.5, [0.08, 0.09, 0.10]);
        $ops[] = $this->text(310, 669, 'Transaction ID (TID): '.$tid, 'F1', 9.5, [0.20, 0.23, 0.26]);
        $ops[] = $this->text(310, 654, 'Order Date: '.$placedAt, 'F1', 9, [0.20, 0.23, 0.26]);
        $ops[] = $this->text(310, 641, 'Verified At: '.$paidAt, 'F1', 8.5, [0.43, 0.46, 0.50]);

        // Items Table Header (y=600)
        $y = 600;
        $ops[] = '0.08 0.09 0.10 rg';
        $ops[] = "40 {$y} 515 22 re f";
        $ops[] = $this->text(52, $y + 7, 'PRODUCT / SUBSCRIPTION DESCRIPTION', 'F2', 8.5, [1, 1, 1]);
        $ops[] = $this->text(340, $y + 7, 'QTY', 'F2', 8.5, [1, 1, 1]);
        $ops[] = $this->text(395, $y + 7, 'UNIT PRICE', 'F2', 8.5, [1, 1, 1]);
        $ops[] = $this->text(480, $y + 7, 'TOTAL', 'F2', 8.5, [1, 1, 1]);

        $y -= 24;
        foreach ($order->items as $item) {
            $ops[] = '0.88 0.90 0.92 RG 0.6 w';
            $ops[] = "40 {$y} 515 24 re S";

            $prodName = $this->clean(mb_strimwidth((string) $item->product_name, 0, 52, '...'));
            $qty = (string) $item->quantity;
            $unitPkr = 'PKR '.number_format((float) $item->unit_price, 0);
            $linePkr = 'PKR '.number_format((float) $item->line_total, 0);

            $ops[] = $this->text(52, $y + 8, $prodName, 'F2', 9.5, [0.08, 0.09, 0.10]);
            $ops[] = $this->text(345, $y + 8, $qty, 'F1', 9.5, [0.20, 0.23, 0.26]);
            $ops[] = $this->text(395, $y + 8, $unitPkr, 'F1', 9.5, [0.20, 0.23, 0.26]);
            $ops[] = $this->text(480, $y + 8, $linePkr, 'F2', 9.5, [0.08, 0.09, 0.10]);

            $y -= 24;
        }

        // Totals Summary Box
        $y -= 14;
        $ops[] = '0.96 0.97 0.98 rg';
        $ops[] = "320 {$y} 235 34 re f";
        $ops[] = '0.08 0.09 0.10 RG 1 w';
        $ops[] = "320 {$y} 235 34 re S";
        $ops[] = $this->text(332, $y + 19, 'TOTAL PAID:', 'F2', 10, [0.08, 0.09, 0.10]);
        $ops[] = $this->text(425, $y + 19, $totalPkr, 'F2', 11.5, [0.08, 0.47, 0.29]);
        $ops[] = $this->text(425, $y + 6, '('.$totalUsd.')', 'F1', 8.5, [0.43, 0.46, 0.50]);

        // Customer Submitted Activation Input (if any)
        if (filled($order->customer_input)) {
            $y -= 42;
            $ops[] = '0.97 0.98 0.99 rg';
            $ops[] = "40 {$y} 515 32 re f";
            $ops[] = '0.85 0.87 0.90 RG 0.6 w';
            $ops[] = "40 {$y} 515 32 re S";
            $ops[] = $this->text(52, $y + 19, 'CUSTOMER ACTIVATION DETAILS:', 'F2', 8.5, [0.43, 0.46, 0.50]);
            $ops[] = $this->text(52, $y + 7, $this->clean(mb_strimwidth(preg_replace('/\s+/', ' ', (string) $order->customer_input), 0, 90, '...')), 'F1', 9.5, [0.08, 0.09, 0.10]);
        }

        // Delivered Credentials Section (if any)
        $deliveredLines = [];
        foreach ($order->items as $item) {
            foreach ($item->deliveries as $del) {
                foreach (explode("\n", trim((string) $del->content)) as $rawLine) {
                    $cleanLine = $this->clean(trim($rawLine));
                    if ($cleanLine !== '') {
                        $deliveredLines[] = mb_strimwidth($cleanLine, 0, 88, '...');
                    }
                }
            }
        }

        if ($deliveredLines !== [] && $y > 160) {
            $y -= 24;
            $ops[] = $this->text(40, $y, 'DELIVERED CREDENTIALS / ACCESS DETAILS:', 'F2', 9.5, [0.08, 0.09, 0.10]);
            $y -= 8;

            $maxLines = min(count($deliveredLines), 12);
            $boxHeight = ($maxLines * 14) + 16;
            $boxY = max(80, $y - $boxHeight);

            $ops[] = '0.08 0.09 0.10 rg';
            $ops[] = "40 {$boxY} 515 {$boxHeight} re f";

            $lineY = $y - 14;
            foreach (array_slice($deliveredLines, 0, $maxLines) as $dLine) {
                $ops[] = $this->text(52, $lineY, $dLine, 'F1', 9, [0.29, 0.87, 0.50]);
                $lineY -= 14;
            }
        }

        // Footer
        $ops[] = '0.88 0.90 0.92 RG 0.6 w';
        $ops[] = '40 58 515 0.5 re S';
        $ops[] = $this->text(40, 42, 'Thank you for shopping with '.$storeName.'! Verify or view this order online anytime at: '.url('/track'), 'F1', 8.5, [0.43, 0.46, 0.50]);
        $ops[] = $this->text(40, 29, 'Powered by AivraSol Softwares (https://aivrasol.com) · Generated on '.now()->format('d M Y, h:i A'), 'F1', 8, [0.55, 0.58, 0.62]);

        return $this->compilePdf(implode("\n", $ops));
    }

    private function text(float $x, float $y, string $text, string $font, float $size, array $rgb): string
    {
        $r = number_format($rgb[0], 2, '.', '');
        $g = number_format($rgb[1], 2, '.', '');
        $b = number_format($rgb[2], 2, '.', '');
        $esc = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);

        return "BT /{$font} {$size} Tf {$r} {$g} {$b} rg {$x} {$y} Td ({$esc}) Tj ET";
    }

    private function clean(string $val): string
    {
        // Convert UTF-8 text into clean printable ASCII/Latin-1 for standard PDF Type1 fonts
        $val = str_replace(['—', '–', '·', '×', '✓', '★'], ['-', '-', '|', 'x', '[OK]', '*'], $val);
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $val);
        $clean = is_string($converted) && $converted !== '' ? $converted : preg_replace('/[^\x20-\x7E]/', '', $val);

        return trim((string) $clean);
    }

    private function compilePdf(string $stream): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $objects[6] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }
}

