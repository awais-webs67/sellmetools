<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Setting;

/**
 * Clean, executive PDF 1.4 Invoice & Receipt Generator with embedded Store Logo.
 * Runs on-demand when the customer downloads the PDF — zero external dependencies.
 */
class ReceiptPdfGenerator
{
    public function generate(Order $order): string
    {
        $order->loadMissing(['items.deliveries', 'paymentMethod', 'latestPayment']);

        $storeName = $this->clean((string) Setting::get('store_name', 'SellMeTools'));
        $orderNumber = $this->clean($order->number);
        $isPaid = in_array($order->status, [OrderStatus::Processing, OrderStatus::Completed, OrderStatus::NeedsAttention], true)
            || $order->paid_at !== null;

        $docTitle = $isPaid ? 'PAYMENT RECEIPT' : 'ORDER INVOICE';
        $paymentBadge = match (true) {
            $isPaid => 'PAID',
            $order->status === OrderStatus::PaymentReview => 'VERIFICATION PENDING',
            $order->status === OrderStatus::PendingPayment => 'UNPAID - AWAITING PAYMENT',
            default => $this->clean(strtoupper($order->status->label())),
        };

        $placedAt = $this->clean($order->created_at?->format('d M Y, h:i A') ?? now()->format('d M Y, h:i A'));
        $paidAt = $isPaid
            ? $this->clean(($order->paid_at ?? $order->completed_at)?->format('d M Y, h:i A') ?? $placedAt)
            : 'Pending';

        $customerName = $this->clean($order->customer_name ?: 'Valued Customer');
        $customerEmail = $this->clean($order->email);
        $customerPhone = $this->clean($order->phone ?: ($order->latestPayment?->sender_number ?: '-'));
        $gatewayName = $this->clean($order->paymentMethod?->name ?: 'Instant / Free');
        $tid = $this->clean($order->latestPayment?->transaction_id ?: 'Not submitted');
        $totalPkr = 'PKR '.number_format((float) $order->total, 2);
        $rate = max(1.0, (float) ($order->usdt_rate ?: Setting::usdtRate()));
        $totalUsd = '$'.number_format((float) $order->total / $rate, 2).' USDT';

        $logoJpeg = $this->prepareLogoJpeg();

        $ops = [];

        // Top Accent Line (Green if paid, Charcoal if invoice)
        $ops[] = $isPaid ? '0.09 0.64 0.29 rg' : '0.08 0.09 0.11 rg';
        $ops[] = '40 800 515 4 re f';

        // Left Header: Embedded Logo Image (or crisp vector mark + brand name)
        if ($logoJpeg !== null) {
            $ops[] = 'q 145 0 0 32 40 756 cm /Im1 Do Q';
        } else {
            $ops[] = $this->text(40, 768, mb_strimwidth($storeName, 0, 28, '...'), 'F2', 20, [0.08, 0.09, 0.11]);
        }
        $ops[] = $this->text(40, 742, 'A Project by AivraSol Softwares · https://sellmetools.com', 'F1', 8.5, [0.42, 0.45, 0.50]);

        // Right Header: Document Title, Order Number & Payment Status
        $ops[] = $this->text(380, 776, $docTitle, 'F2', 11, [0.42, 0.45, 0.50]);
        $ops[] = $this->text(380, 759, mb_strimwidth($orderNumber, 0, 24, '...'), 'F3', 12, [0.08, 0.09, 0.11]);
        $badgeColor = $isPaid ? [0.08, 0.50, 0.24] : ($order->status === OrderStatus::PaymentReview ? [0.70, 0.42, 0.04] : [0.75, 0.12, 0.22]);
        $ops[] = $this->text(380, 742, 'Status: '.mb_strimwidth($paymentBadge, 0, 28, '...'), 'F2', 8.5, $badgeColor);

        // Header Divider Line
        $ops[] = '0.88 0.90 0.92 RG 0.8 w';
        $ops[] = '40 728 515 0.5 re S';

        // Metadata Grid Box (x=40, y=636, w=515, h=80)
        $ops[] = '0.97 0.98 0.99 rg';
        $ops[] = '40 636 515 80 re f';
        $ops[] = '0.88 0.90 0.92 RG 0.7 w';
        $ops[] = '40 636 515 80 re S';

        // Left Column: Billed To
        $ops[] = $this->text(52, 699, 'BILLED TO', 'F2', 8, [0.43, 0.46, 0.50]);
        $ops[] = $this->text(52, 683, mb_strimwidth($customerName, 0, 38, '...'), 'F2', 9.5, [0.08, 0.09, 0.11]);
        $ops[] = $this->text(52, 668, mb_strimwidth($customerEmail, 0, 42, '...'), 'F1', 8.5, [0.22, 0.25, 0.30]);
        $ops[] = $this->text(52, 653, 'Phone: '.mb_strimwidth($customerPhone, 0, 34, '...'), 'F1', 8.5, [0.35, 0.38, 0.43]);

        // Right Column: Payment Summary
        $ops[] = $this->text(305, 699, 'PAYMENT SUMMARY', 'F2', 8, [0.43, 0.46, 0.50]);
        $ops[] = $this->text(305, 683, 'Method: '.mb_strimwidth($gatewayName, 0, 30, '...'), 'F2', 9, [0.08, 0.09, 0.11]);
        $ops[] = $this->text(305, 668, 'TID: '.mb_strimwidth($tid, 0, 34, '...'), 'F3', 8.5, [0.20, 0.23, 0.26]);
        $ops[] = $this->text(305, 654, 'Issued: '.mb_strimwidth($placedAt, 0, 28, '...'), 'F1', 8, [0.35, 0.38, 0.43]);
        $ops[] = $this->text(305, 642, ($isPaid ? 'Paid At: ' : 'Payment: ').mb_strimwidth($paidAt, 0, 28, '...'), 'F1', 8, [0.35, 0.38, 0.43]);

        // Items Table Header (y=598)
        $y = 598;
        $ops[] = '0.95 0.96 0.97 rg';
        $ops[] = "40 {$y} 515 22 re f";
        $ops[] = '0.82 0.85 0.88 RG 0.7 w';
        $ops[] = "40 {$y} 515 22 re S";
        $ops[] = $this->text(52, $y + 7, 'DESCRIPTION', 'F2', 8, [0.30, 0.33, 0.38]);
        $ops[] = $this->text(340, $y + 7, 'QTY', 'F2', 8, [0.30, 0.33, 0.38]);
        $ops[] = $this->text(395, $y + 7, 'UNIT PRICE', 'F2', 8, [0.30, 0.33, 0.38]);
        $ops[] = $this->text(480, $y + 7, 'AMOUNT', 'F2', 8, [0.30, 0.33, 0.38]);

        $y -= 24;
        foreach ($order->items as $item) {
            $ops[] = '0.90 0.91 0.93 RG 0.5 w';
            $ops[] = "40 {$y} 515 24 re S";

            $prodName = $this->clean(mb_strimwidth((string) $item->product_name, 0, 46, '...'));
            $qty = (string) $item->quantity;
            $unitPkr = 'PKR '.number_format((float) $item->unit_price, 0);
            $linePkr = 'PKR '.number_format((float) $item->line_total, 0);

            $ops[] = $this->text(52, $y + 8, $prodName, 'F2', 9, [0.08, 0.09, 0.11]);
            $ops[] = $this->text(345, $y + 8, $qty, 'F3', 9, [0.22, 0.25, 0.30]);
            $ops[] = $this->text(395, $y + 8, $unitPkr, 'F3', 8.5, [0.22, 0.25, 0.30]);
            $ops[] = $this->text(480, $y + 8, $linePkr, 'F2', 9, [0.08, 0.09, 0.11]);

            $y -= 24;
        }

        // Totals Summary Box
        $y -= 14;
        $ops[] = '0.97 0.98 0.99 rg';
        $ops[] = "320 {$y} 235 34 re f";
        $ops[] = '0.08 0.09 0.11 RG 0.9 w';
        $ops[] = "320 {$y} 235 34 re S";
        $ops[] = $this->text(332, $y + 19, $isPaid ? 'TOTAL PAID:' : 'AMOUNT DUE:', 'F2', 9.5, [0.08, 0.09, 0.11]);
        $ops[] = $this->text(425, $y + 19, $totalPkr, 'F2', 10.5, $isPaid ? [0.08, 0.47, 0.29] : [0.75, 0.12, 0.22]);
        $ops[] = $this->text(425, $y + 6, 'Approx. '.$totalUsd, 'F1', 8, [0.43, 0.46, 0.50]);

        // Customer Submitted Activation Input (if any)
        if (filled($order->customer_input)) {
            $y -= 42;
            $ops[] = '0.97 0.98 0.99 rg';
            $ops[] = "40 {$y} 515 32 re f";
            $ops[] = '0.85 0.87 0.90 RG 0.6 w';
            $ops[] = "40 {$y} 515 32 re S";
            $ops[] = $this->text(52, $y + 19, 'CUSTOMER ACTIVATION DETAILS', 'F2', 8, [0.43, 0.46, 0.50]);
            $ops[] = $this->text(52, $y + 7, $this->clean(mb_strimwidth(preg_replace('/\s+/', ' ', (string) $order->customer_input), 0, 82, '...')), 'F3', 8.5, [0.08, 0.09, 0.11]);
        }

        // Delivered Credentials Section (Clean light slate box with dark monospace text)
        $deliveredLines = [];
        foreach ($order->items as $item) {
            foreach ($item->deliveries as $del) {
                foreach (explode("\n", trim((string) $del->content)) as $rawLine) {
                    $cleanLine = $this->clean(trim($rawLine));
                    if ($cleanLine !== '') {
                        foreach ($this->wrapText($cleanLine, 82) as $wrappedChunk) {
                            $deliveredLines[] = $wrappedChunk;
                        }
                    }
                }
            }
        }

        if ($deliveredLines !== [] && $y > 160) {
            $y -= 24;
            $ops[] = $this->text(40, $y, 'DELIVERED ACCESS CREDENTIALS', 'F2', 9, [0.08, 0.09, 0.11]);
            $y -= 8;

            $maxLines = min(count($deliveredLines), 16);
            $boxHeight = ($maxLines * 13) + 16;
            $boxY = max(72, $y - $boxHeight);

            $ops[] = '0.97 0.98 0.99 rg';
            $ops[] = "40 {$boxY} 515 {$boxHeight} re f";
            $ops[] = '0.82 0.85 0.88 RG 0.7 w';
            $ops[] = "40 {$boxY} 515 {$boxHeight} re S";

            $lineY = $y - 14;
            foreach (array_slice($deliveredLines, 0, $maxLines) as $dLine) {
                $ops[] = $this->text(52, $lineY, $dLine, 'F3', 8.5, [0.08, 0.09, 0.11]);
                $lineY -= 13;
            }
        }

        // Footer
        $ops[] = '0.88 0.90 0.92 RG 0.6 w';
        $ops[] = '40 58 515 0.5 re S';
        $ops[] = $this->text(40, 42, 'Thank you for choosing '.$storeName.'. View or verify your order online at: https://sellmetools.com/track', 'F1', 8.5, [0.43, 0.46, 0.50]);
        $ops[] = $this->text(40, 29, 'Issued by AivraSol Softwares (https://aivrasol.com) · Generated on '.now()->format('d M Y, h:i A'), 'F1', 8, [0.55, 0.58, 0.62]);

        return $this->compilePdf(implode("\n", $ops), $logoJpeg);
    }

    /**
     * Loads public/images/logo.png onto a crisp white canvas and returns JPEG bytes + dimensions for PDF /DCTDecode.
     *
     * @return array{bytes: string, width: int, height: int}|null
     */
    private function prepareLogoJpeg(): ?array
    {
        if (! function_exists('imagecreatefrompng') || ! function_exists('imagejpeg')) {
            return null;
        }

        $path = public_path('images/logo.png');
        if (! is_file($path)) {
            return null;
        }

        $src = @imagecreatefrompng($path);
        if (! $src) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= 0 || $h <= 0) {
            imagedestroy($src);

            return null;
        }

        $canvas = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
        imagealphablending($canvas, true);
        imagecopy($canvas, $src, 0, 0, 0, 0, $w, $h);

        ob_start();
        imagejpeg($canvas, null, 95);
        $jpegBytes = (string) ob_get_clean();

        imagedestroy($src);
        imagedestroy($canvas);

        return $jpegBytes !== '' ? ['bytes' => $jpegBytes, 'width' => $w, 'height' => $h] : null;
    }

    /**
     * @return string[]
     */
    private function wrapText(string $text, int $maxChars = 82): array
    {
        if (strlen($text) <= $maxChars) {
            return [$text];
        }

        $wrapped = wordwrap($text, $maxChars, "\n", true);

        return array_values(array_filter(explode("\n", $wrapped), fn (string $l): bool => $l !== ''));
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
        $val = str_replace(['—', '–', '·', '×', '✓', '★'], ['-', '-', '|', 'x', '[OK]', '*'], $val);
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $val);
        $clean = is_string($converted) && $converted !== '' ? $converted : preg_replace('/[^\x20-\x7E]/', '', $val);

        return trim((string) $clean);
    }

    /**
     * @param  array{bytes: string, width: int, height: int}|null  $logoJpeg
     */
    private function compilePdf(string $stream, ?array $logoJpeg = null): string
    {
        $xObjectRef = $logoJpeg !== null ? ' /XObject << /Im1 8 0 R >>' : '';

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R /F3 6 0 R >>'.$xObjectRef.' >> /Contents 7 0 R >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $objects[6] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';
        $objects[7] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";

        if ($logoJpeg !== null) {
            $len = strlen($logoJpeg['bytes']);
            $w = $logoJpeg['width'];
            $h = $logoJpeg['height'];
            $objects[8] = "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$len} >>\nstream\n{$logoJpeg['bytes']}\nendstream";
        }

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
