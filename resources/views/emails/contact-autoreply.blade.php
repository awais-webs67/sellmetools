@php
    $logoUrl = asset('images/logo.png');
    $waNumber = function_exists('admin_whatsapp_number') ? admin_whatsapp_number() : '';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>We Received Your Message - {{ $storeName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0F172A;line-height:1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#F1F5F9;padding:32px 14px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background-color:#FFFFFF;border-radius:16px;border:1px solid #E2E8F0;overflow:hidden;box-shadow:0 10px 25px -5px rgba(15,23,42,0.06);">

                    {{-- Top White Brand Logo Header --}}
                    <tr>
                        <td style="background-color:#FFFFFF;padding:22px 28px;border-bottom:1px solid #F1F5F9;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="left" valign="middle">
                                        <a href="{{ url('/') }}" style="text-decoration:none;display:inline-block;">
                                            <img src="{{ $logoUrl }}" alt="{{ $storeName }}" width="160" style="display:block;height:34px;width:auto;max-width:170px;border:0;">
                                        </a>
                                    </td>
                                    <td align="right" valign="middle">
                                        <span style="display:inline-block;background-color:#ECFDF5;border:1px solid #A7F3D0;color:#047857;font-size:11px;font-weight:700;padding:5px 12px;border-radius:999px;letter-spacing:0.3px;text-transform:uppercase;">
                                            ● Ticket Received
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Dark Hero Banner --}}
                    <tr>
                        <td style="background-color:#14171A;background-image:linear-gradient(135deg,#14171A 0%,#2B0912 100%);padding:30px 28px;text-align:center;color:#FFFFFF;">
                            <p style="margin:0;font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#34D399;">
                                24/7 Customer Support Desk
                            </p>
                            <h1 style="margin:8px 0 6px;font-size:23px;font-weight:800;letter-spacing:-0.3px;color:#FFFFFF;">
                                Thank You for Contacting {{ $storeName }}
                            </h1>
                            <p style="margin:0;font-size:13px;color:rgba(255,255,255,0.78);">
                                Our support team has received your inquiry and will reply shortly.
                            </p>
                        </td>
                    </tr>

                    {{-- Main Body --}}
                    <tr>
                        <td style="padding:30px 28px;">
                            <p style="margin:0 0 14px;font-size:15px;font-weight:600;color:#0F172A;">
                                Assalam o Alaikum {{ $inquiry['name'] }},
                            </p>
                            <p style="margin:0 0 20px;font-size:14px;color:#475569;line-height:1.65;">
                                Thank you for reaching out to <strong>{{ $storeName }}</strong>. We have logged your message and a member of our support desk will get back to you at <strong>{{ $inquiry['email'] }}</strong> as soon as possible.
                            </p>

                            {{-- Inquiry Summary Box --}}
                            <div style="margin:22px 0;border:1px solid #CBD5E1;border-radius:12px;overflow:hidden;background-color:#F8FAFC;">
                                <div style="background-color:#F1F5F9;padding:12px 16px;border-bottom:1px solid #E2E8F0;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;color:#475569;">
                                    Your Message Summary
                                </div>
                                <div style="padding:16px 18px;">
                                    <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#0F172A;">
                                        Topic: {{ $inquiry['subject'] }}
                                        @if (!empty($inquiry['order_number']))
                                            <span style="display:inline-block;margin-left:6px;background-color:#E2E8F0;padding:2px 8px;border-radius:6px;font-family:monospace;font-size:12px;">#{{ $inquiry['order_number'] }}</span>
                                        @endif
                                    </p>
                                    <p style="margin:0;font-size:13.5px;color:#334155;white-space:pre-line;line-height:1.6;">{{ $inquiry['message'] }}</p>
                                </div>
                            </div>

                            {{-- Action Buttons --}}
                            <div style="text-align:center;margin:26px 0 10px;">
                                @if ($waNumber !== '')
                                    <a href="https://wa.me/{{ $waNumber }}" style="display:inline-block;background-color:#16A34A;color:#FFFFFF;font-weight:700;font-size:13px;padding:13px 24px;border-radius:10px;text-decoration:none;margin:4px;">
                                        Chat on WhatsApp Support →
                                    </a>
                                @endif
                                <a href="{{ route('shop') }}" style="display:inline-block;background-color:#14171A;color:#FFFFFF;font-weight:700;font-size:13px;padding:13px 24px;border-radius:10px;text-decoration:none;margin:4px;">
                                    Browse Store Catalog →
                                </a>
                            </div>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background-color:#F8FAFC;padding:20px 28px;border-top:1px solid #E2E8F0;text-align:center;font-size:11px;color:#94A3B8;">
                            &copy; {{ date('Y') }} {{ $storeName }} ({{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'sellmetools.com' }}). A Project by AivraSol Softwares.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
