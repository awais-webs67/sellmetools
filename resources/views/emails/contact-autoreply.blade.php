@php
    $logoUrl = asset('images/email-logo.png');
    $waNumber = function_exists('admin_whatsapp_number') ? admin_whatsapp_number() : '';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark light">
    <meta name="supported-color-schemes" content="dark light">
    <title>We Received Your Message - {{ $storeName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#060911;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#F8FAFC;line-height:1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#060911;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background-color:#0D1322;border-radius:18px;border:1px solid #1E293B;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,0.6);">

                    {{-- Top Brand Accent Line --}}
                    <tr>
                        <td style="height:4px;background:linear-gradient(90deg,#9B1B30 0%,#E11D48 50%,#10B981 100%);font-size:0;line-height:0;">&nbsp;</td>
                    </tr>

                    {{-- Dark Luxury Brand Header with High-Contrast Logo Pill --}}
                    <tr>
                        <td style="background-color:#090D18;padding:20px 26px;border-bottom:1px solid #1E293B;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="left" valign="middle">
                                        <a href="{{ url('/') }}" style="text-decoration:none;display:inline-block;background-color:#FFFFFF;background-image:linear-gradient(#FFFFFF,#FFFFFF);padding:7px 14px;border-radius:10px;border:1px solid #E2E8F0;box-shadow:0 4px 12px rgba(0,0,0,0.35);">
                                            <img src="{{ $logoUrl }}" alt="{{ $storeName }}" width="155" style="display:block;height:32px;width:auto;max-width:165px;border:0;">
                                        </a>
                                    </td>
                                    <td align="right" valign="middle">
                                        <span style="display:inline-block;background-color:#064E3B;border:1px solid #10B981;color:#6EE7B7;font-size:11px;font-weight:800;padding:5px 12px;border-radius:999px;">
                                            Ticket Received
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Dark Banner --}}
                    <tr>
                        <td style="background-color:#111827;background-image:linear-gradient(180deg,#131C31 0%,#0D1322 100%);padding:26px;text-align:center;border-bottom:1px solid #1E293B;">
                            <p style="margin:0;font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;color:#34D399;">
                                24/7 Customer Support Desk
                            </p>
                            <h1 style="margin:8px 0 4px;font-size:22px;font-weight:800;color:#FFFFFF;">
                                Thank You for Contacting {{ $storeName }}
                            </h1>
                            <p style="margin:0;font-size:13.5px;color:#94A3B8;">
                                Our support team has received your inquiry and will reply shortly.
                            </p>
                        </td>
                    </tr>

                    {{-- Main Body --}}
                    <tr>
                        <td style="padding:26px;">
                            <p style="margin:0 0 14px;font-size:15px;font-weight:700;color:#FFFFFF;">
                                Assalam o Alaikum {{ $inquiry['name'] }},
                            </p>
                            <p style="margin:0 0 20px;font-size:14px;color:#CBD5E1;line-height:1.65;">
                                Thank you for reaching out to <strong style="color:#FFFFFF;">{{ $storeName }}</strong>. We have logged your message and a member of our support desk will get back to you at <strong style="color:#38BDF8;">{{ $inquiry['email'] }}</strong> as soon as possible.
                            </p>

                            {{-- Inquiry Summary Box --}}
                            <div style="margin:20px 0;border:1px solid #1E293B;border-radius:14px;overflow:hidden;background-color:#080C16;">
                                <div style="background-color:#131C31;padding:12px 18px;border-bottom:1px solid #1E293B;font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:0.8px;color:#94A3B8;">
                                    Your Message Summary
                                </div>
                                <div style="padding:18px;border-left:4px solid #10B981;">
                                    <p style="margin:0 0 10px;font-size:14px;font-weight:800;color:#FFFFFF;">
                                        Topic: {{ $inquiry['subject'] }}
                                        @if (!empty($inquiry['order_number']))
                                            <span style="display:inline-block;margin-left:6px;background-color:#1E293B;border:1px solid #334155;color:#38BDF8;padding:2px 8px;border-radius:6px;font-family:monospace;font-size:12px;">#{{ $inquiry['order_number'] }}</span>
                                        @endif
                                    </p>
                                    <p style="margin:0;font-size:13.5px;color:#CBD5E1;white-space:pre-line;line-height:1.65;">{{ $inquiry['message'] }}</p>
                                </div>
                            </div>

                            {{-- Action Buttons --}}
                            <div style="text-align:center;margin:24px 0 8px;">
                                @if ($waNumber !== '')
                                    <a href="https://wa.me/{{ $waNumber }}" style="display:inline-block;background-color:#25D366;color:#FFFFFF;font-weight:800;font-size:13px;padding:12px 22px;border-radius:10px;text-decoration:none;margin:4px;">
                                        WhatsApp Support
                                    </a>
                                @endif
                                <a href="{{ route('shop') }}" style="display:inline-block;background-color:#162033;border:1px solid #334155;color:#FFFFFF;font-weight:700;font-size:13px;padding:12px 22px;border-radius:10px;text-decoration:none;margin:4px;">
                                    Browse Store Catalog
                                </a>
                            </div>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background-color:#080C16;padding:18px 26px;border-top:1px solid #1E293B;text-align:center;font-size:11px;color:#64748B;">
                            &copy; {{ date('Y') }} {{ $storeName }} ({{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'sellmetools.com' }}). All rights reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
