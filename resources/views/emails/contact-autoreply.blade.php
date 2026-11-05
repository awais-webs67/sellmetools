@php
    $storeName = $storeName ?? (string) setting('store_name', 'SellMeTools');
    $logoUrl = asset('images/email-logo-dark.png') . '?v=4';
    $waNumber = function_exists('admin_whatsapp_number') ? admin_whatsapp_number() : '';
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>We Received Your Message - {{ $storeName }}</title>
    <style>
        body, table, td, p, a, h1, h2 {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        table, td {
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
            border-collapse: collapse;
        }
        @media only screen and (max-width: 600px) {
            .email-wrapper { padding: 12px 8px !important; }
            .email-card { border-radius: 14px !important; }
            .hero-pad { padding: 26px 18px !important; }
            .body-pad { padding: 20px 16px !important; }
            .hero-title { font-size: 21px !important; }
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

                    {{-- Storefront Hero Banner (Wine Red + Pure Black) --}}
                    <tr>
                        <td align="center" bgcolor="#140409" class="hero-pad" style="padding:32px 24px 26px 24px;background-color:#140409;background-image:radial-gradient(ellipse at top, rgba(180,28,54,0.48), transparent 62%), linear-gradient(145deg, #100307 0%, #3A0916 52%, #0C0306 100%);border-bottom:1px solid #3A121E;text-align:center;">
                            <a href="{{ url('/') }}" target="_blank" style="text-decoration:none;display:inline-block;">
                                <img src="{{ $logoUrl }}" alt="{{ $storeName }}" width="175" height="34" style="display:block;margin:0 auto;width:175px;max-width:78%;height:auto;border:0;">
                            </a>

                            <div style="margin-top:18px;">
                                <span style="display:inline-block;background-color:#280812;border:1px solid #7D1426;color:#FDA4AF;font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;padding:5px 14px;border-radius:999px;">
                                    24/7 Support Desk
                                </span>
                            </div>

                            <h1 class="hero-title" style="margin:14px 0 6px 0;font-size:23px;font-weight:800;color:#FFFFFF;letter-spacing:-0.02em;line-height:1.25;">
                                Thank You for Contacting {{ $storeName }}
                            </h1>
                            <p style="margin:0;font-size:13px;color:#D4D4D8;">
                                Our support team has received your inquiry and will reply shortly.
                            </p>
                        </td>
                    </tr>

                    {{-- Main Body --}}
                    <tr>
                        <td class="body-pad" style="padding:24px;">
                            <p style="margin:0 0 12px 0;font-size:15px;font-weight:700;color:#FFFFFF;">
                                Assalam o Alaikum {{ $inquiry['name'] }},
                            </p>
                            <p style="margin:0 0 18px 0;font-size:14px;color:#D4D4D8;line-height:1.65;">
                                Thank you for reaching out to <strong style="color:#FFFFFF;">{{ $storeName }}</strong>. We have logged your message and a member of our support desk will get back to you at <strong style="color:#FDA4AF;">{{ $inquiry['email'] }}</strong> as soon as possible.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#171216" style="width:100%;background-color:#171216;border:1px solid #2E1821;border-left:3px solid #E11D48;border-radius:12px;margin-bottom:20px;">
                                <tr>
                                    <td style="padding:14px 16px;">
                                        <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:0.08em;color:#FDA4AF;margin-bottom:6px;">
                                            Topic: {{ $inquiry['subject'] }}
                                            @if (!empty($inquiry['order_number']))
                                                &middot; Order #{{ $inquiry['order_number'] }}
                                            @endif
                                        </div>
                                        <div style="font-size:13.5px;color:#F4F4F5;white-space:pre-line;line-height:1.65;word-break:break-word;">{{ $inquiry['message'] }}</div>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
                                <tr>
                                    @if ($waNumber !== '')
                                        <td class="stack-btn" width="50%" style="padding-right:5px;">
                                            <a href="https://wa.me/{{ $waNumber }}" target="_blank" style="display:block;padding:13px 14px;font-size:13px;font-weight:800;color:#FFFFFF;text-decoration:none;text-align:center;border-radius:10px;background-color:#9B1B30;border:1px solid #BE123C;">
                                                WhatsApp Support
                                            </a>
                                        </td>
                                    @endif
                                    <td class="stack-btn" width="{{ $waNumber !== '' ? '50%' : '100%' }}" style="padding-left:{{ $waNumber !== '' ? '5px' : '0' }};">
                                        <a href="{{ route('shop') }}" target="_blank" style="display:block;padding:13px 14px;font-size:13px;font-weight:700;color:#F4F4F5;text-decoration:none;text-align:center;border-radius:10px;background-color:#1A1A22;border:1px solid #2E2E3A;">
                                            Browse Store Catalog
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td bgcolor="#0C0C10" style="padding:18px 24px;background-color:#0C0C10;border-top:1px solid #24131A;text-align:center;font-size:11px;color:#71717A;">
                            &copy; {{ date('Y') }} {{ $storeName }} ({{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'sellmetools.com' }}). All rights reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
