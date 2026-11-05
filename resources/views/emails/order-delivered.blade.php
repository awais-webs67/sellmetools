@php
    $storeName = $storeName ?? (string) setting('store_name', 'SellMeTools');
    $logoUrl = asset('images/email-logo-dark.png') . '?v=4';
    $waNumber = function_exists('admin_whatsapp_number') ? admin_whatsapp_number() : '';
    $orderUrl = $orderUrl ?? $order->customerUrl();
    $receiptUrl = route('orders.receipt', ['order' => $order->number, 'token' => $order->access_token]);
    $pdfReceiptUrl = route('orders.receipt', ['order' => $order->number, 'token' => $order->access_token, 'download' => 'pdf']);
    $itemsToDisplay = isset($deliveredItems) && $deliveredItems->isNotEmpty() ? $deliveredItems : $order->items;
    $totalFormatted = (float) $order->total <= 0 ? 'Rs 0 (Free)' : money($order->total);
    $supportEmail = (string) setting('support_email', 'contact@sellmetools.com');
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>Order #{{ $order->number }} Delivered - {{ $storeName }}</title>
    <style>
        body, table, td, p, a, h1, h2, h3 {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        table, td {
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
            border-collapse: collapse;
        }
        img {
            -ms-interpolation-mode: bicubic;
            border: 0;
            outline: none;
            text-decoration: none;
        }
        @media only screen and (max-width: 600px) {
            .email-wrapper {
                padding: 12px 8px !important;
            }
            .email-card {
                border-radius: 14px !important;
            }
            .hero-pad {
                padding: 26px 18px 22px 18px !important;
            }
            .body-pad {
                padding: 20px 16px !important;
            }
            .hero-title {
                font-size: 21px !important;
                line-height: 1.25 !important;
            }
            .cred-box {
                font-size: 12.5px !important;
                padding: 14px !important;
            }
            .stack-btn {
                display: block !important;
                width: 100% !important;
                padding: 0 0 8px 0 !important;
            }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#09090B;color:#F4F4F5;width:100% !important;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#09090B" style="background-color:#09090B;width:100%;margin:0;padding:0;">
        <tr>
            <td align="center" class="email-wrapper" style="padding:28px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="email-card" bgcolor="#111115" style="max-width:580px;width:100%;background-color:#111115;border:1px solid #2E151E;border-radius:16px;overflow:hidden;">

                    {{-- Top Wine-Red Hero Accent Line --}}
                    <tr>
                        <td height="4" bgcolor="#9B1B30" style="height:4px;line-height:4px;font-size:4px;background-color:#9B1B30;background-image:linear-gradient(90deg,#7D1426 0%,#E11D48 50%,#7D1426 100%);">&nbsp;</td>
                    </tr>

                    {{-- Storefront Hero Banner (Wine Red #9B1B30 + Pure Black #100307) --}}
                    <tr>
                        <td align="center" bgcolor="#140409" class="hero-pad" style="padding:32px 24px 26px 24px;background-color:#140409;background-image:radial-gradient(ellipse at top, rgba(180,28,54,0.48), transparent 62%), linear-gradient(145deg, #100307 0%, #3A0916 52%, #0C0306 100%);border-bottom:1px solid #3A121E;text-align:center;">
                            <a href="{{ url('/') }}" target="_blank" style="text-decoration:none;display:inline-block;">
                                <img src="{{ $logoUrl }}" alt="{{ $storeName }}" width="175" height="34" style="display:block;margin:0 auto;width:175px;max-width:78%;height:auto;border:0;">
                            </a>

                            <div style="margin-top:18px;">
                                <span style="display:inline-block;background-color:#280812;border:1px solid #7D1426;color:#FDA4AF;font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;padding:5px 14px;border-radius:999px;">
                                    Order Delivered
                                </span>
                            </div>

                            <h1 class="hero-title" style="margin:14px 0 6px 0;font-size:24px;font-weight:800;color:#FFFFFF;letter-spacing:-0.02em;line-height:1.25;">
                                Order Delivered Successfully
                            </h1>
                            <p style="margin:0;font-size:13.5px;color:#D4D4D8;line-height:1.55;">
                                Please copy your product details below.
                            </p>
                        </td>
                    </tr>

                    {{-- Main Body --}}
                    <tr>
                        <td class="body-pad" style="padding:24px;">

                            {{-- Mobile-Responsive Key-Value Order Summary Card --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#171216" style="width:100%;background-color:#171216;border:1px solid #2E1821;border-radius:12px;margin-bottom:22px;">
                                <tr>
                                    <td style="padding:11px 16px;border-bottom:1px solid #26141C;font-size:12px;color:#A1A1AA;font-weight:600;">
                                        Order ID
                                    </td>
                                    <td align="right" style="padding:11px 16px;border-bottom:1px solid #26141C;font-size:13px;color:#FFFFFF;font-weight:800;font-family:Consolas,Monaco,'Courier New',monospace;">
                                        #{{ $order->number }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:11px 16px;border-bottom:1px solid #26141C;font-size:12px;color:#A1A1AA;font-weight:600;">
                                        Total Paid
                                    </td>
                                    <td align="right" style="padding:11px 16px;border-bottom:1px solid #26141C;font-size:13px;color:#FDA4AF;font-weight:800;">
                                        {{ $totalFormatted }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:11px 16px;font-size:12px;color:#A1A1AA;font-weight:600;">
                                        Date
                                    </td>
                                    <td align="right" style="padding:11px 16px;font-size:12.5px;color:#E4E4E7;font-weight:600;">
                                        {{ $order->created_at->format('d M Y') }}
                                    </td>
                                </tr>
                            </table>

                            {{-- Credentials Heading --}}
                            <div style="margin:0 0 12px 0;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:0.08em;color:#FDA4AF;">
                                Delivered Access Credentials
                            </div>

                            @foreach ($itemsToDisplay as $item)
                                @php
                                    $lines = $item->deliveries
                                        ->map(fn ($d) => trim((string) $d->content))
                                        ->filter()
                                        ->values();

                                    if ($lines->isEmpty()) {
                                        if (filled($order->customer_input)) {
                                            $lines = collect(["Activated on your submitted account:\n".trim((string) $order->customer_input)]);
                                        } else {
                                            $lines = collect(["Order fulfilled and activated.\nOrder ID: #{$order->number}"]);
                                        }
                                    }
                                @endphp
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#16161C" style="width:100%;background-color:#16161C;border:1px solid #2A1A22;border-radius:12px;margin-bottom:16px;overflow:hidden;">
                                    <tr>
                                        <td style="padding:13px 16px;background-color:#1C1217;border-bottom:1px solid #2A1A22;">
                                            <div style="font-size:14.5px;font-weight:800;color:#FFFFFF;line-height:1.35;">
                                                {{ $item->product_name }}
                                            </div>
                                            <div style="margin-top:3px;font-size:11.5px;color:#FDA4AF;font-weight:600;">
                                                Quantity: {{ $item->quantity }}
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:14px 16px;">
                                            <div class="cred-box" style="background-color:#09090D;padding:15px 16px;border-radius:8px;border:1px solid #3A1522;border-left:3px solid #E11D48;font-family:Consolas,Monaco,'Courier New',monospace;font-size:13px;font-weight:600;color:#F8FAFC;white-space:pre-wrap;word-break:break-word;line-height:1.65;">{{ $lines->implode("\n\n----------------------------------------\n\n") }}</div>
                                        </td>
                                    </tr>
                                </table>
                            @endforeach

                            {{-- Primary CTA (Wine Red Hero Button) --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin-top:8px;">
                                <tr>
                                    <td align="center" bgcolor="#9B1B30" style="border-radius:10px;background-color:#9B1B30;">
                                        <a href="{{ $orderUrl }}" target="_blank" style="display:block;padding:14px 20px;font-size:14px;font-weight:800;color:#FFFFFF;text-decoration:none;text-align:center;border-radius:10px;background-color:#9B1B30;border:1px solid #BE123C;">
                                            Open Order &amp; Copy Details
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            {{-- Secondary Actions (Responsive Stacked on Mobile) --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin-top:10px;">
                                <tr>
                                    <td class="stack-btn" width="{{ $waNumber !== '' ? '33.33%' : '50%' }}" style="padding-right:4px;">
                                        <a href="{{ $receiptUrl }}" target="_blank" style="display:block;padding:11px 10px;font-size:12px;font-weight:700;color:#F4F4F5;text-decoration:none;text-align:center;border-radius:9px;background-color:#1A1A22;border:1px solid #2E2E3A;">
                                            View Receipt
                                        </a>
                                    </td>
                                    <td class="stack-btn" width="{{ $waNumber !== '' ? '33.33%' : '50%' }}" style="padding-left:4px;padding-right:{{ $waNumber !== '' ? '4px' : '0' }};">
                                        <a href="{{ $pdfReceiptUrl }}" target="_blank" style="display:block;padding:11px 10px;font-size:12px;font-weight:700;color:#F4F4F5;text-decoration:none;text-align:center;border-radius:9px;background-color:#1A1A22;border:1px solid #2E2E3A;">
                                            Download Receipt
                                        </a>
                                    </td>
                                    @if ($waNumber !== '')
                                        <td class="stack-btn" width="33.33%" style="padding-left:4px;">
                                            <a href="https://wa.me/{{ $waNumber }}" target="_blank" style="display:block;padding:11px 10px;font-size:12px;font-weight:700;color:#FFFFFF;text-decoration:none;text-align:center;border-radius:9px;background-color:#15803D;border:1px solid #22C55E;">
                                                WhatsApp Support
                                            </a>
                                        </td>
                                    @endif
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#151115" style="width:100%;background-color:#151115;border:1px solid #291820;border-radius:10px;margin-top:14px;">
                                <tr>
                                    <td style="padding:12px 14px;text-align:center;font-size:12px;color:#A1A1AA;line-height:1.55;">
                                        Your delivered credentials are also permanently saved in your
                                        <a href="{{ route('account.orders') }}" style="color:#FDA4AF;font-weight:700;text-decoration:underline;">Customer Order Vault</a>.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td bgcolor="#0C0C10" style="padding:18px 24px;background-color:#0C0C10;border-top:1px solid #24131A;text-align:center;">
                            <div style="margin-bottom:6px;font-size:12px;">
                                <a href="{{ url('/') }}" style="color:#A1A1AA;text-decoration:none;margin:0 6px;font-weight:600;">Store</a>
                                <span style="color:#3F3F46;">&middot;</span>
                                <a href="{{ route('shop') }}" style="color:#A1A1AA;text-decoration:none;margin:0 6px;font-weight:600;">Catalog</a>
                                <span style="color:#3F3F46;">&middot;</span>
                                <a href="{{ route('account.orders') }}" style="color:#A1A1AA;text-decoration:none;margin:0 6px;font-weight:600;">My Orders</a>
                            </div>
                            <div style="font-size:11px;color:#71717A;">
                                &copy; {{ date('Y') }} {{ $storeName }} ({{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'sellmetools.com' }}). All rights reserved.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
