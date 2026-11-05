@php
    $logoUrl = asset('images/email-logo.png');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark light">
    <meta name="supported-color-schemes" content="dark light">
    <title>New Contact Inquiry - {{ $storeName }}</title>
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
                                        <span style="display:inline-block;background-color:#4C0519;border:1px solid #E11D48;color:#FDA4AF;font-size:11px;font-weight:800;padding:5px 12px;border-radius:999px;">
                                            New Support Ticket
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Dark Banner --}}
                    <tr>
                        <td style="background-color:#111827;background-image:linear-gradient(180deg,#131C31 0%,#0D1322 100%);padding:24px 26px;border-bottom:1px solid #1E293B;">
                            <p style="margin:0;font-size:11px;letter-spacing:1.2px;text-transform:uppercase;color:#94A3B8;font-weight:800;">Customer Support Desk</p>
                            <h1 style="margin:6px 0 0;font-size:21px;font-weight:800;color:#FFFFFF;">New Contact Message on {{ $storeName }}</h1>
                        </td>
                    </tr>

                    {{-- Main Body --}}
                    <tr>
                        <td style="padding:26px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:14px;">
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #1E293B;color:#94A3B8;width:140px;font-weight:600;">Customer Name:</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #1E293B;color:#FFFFFF;font-weight:700;">{{ $inquiry['name'] }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #1E293B;color:#94A3B8;font-weight:600;">Reply Email:</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #1E293B;color:#38BDF8;font-weight:700;">
                                        <a href="mailto:{{ $inquiry['email'] }}" style="color:#38BDF8;text-decoration:underline;">{{ $inquiry['email'] }}</a>
                                    </td>
                                </tr>
                                @if (!empty($inquiry['phone']))
                                    <tr>
                                        <td style="padding:10px 0;border-bottom:1px solid #1E293B;color:#94A3B8;font-weight:600;">WhatsApp / Phone:</td>
                                        <td style="padding:10px 0;border-bottom:1px solid #1E293B;color:#34D399;font-weight:700;">{{ $inquiry['phone'] }}</td>
                                    </tr>
                                @endif
                                @if (!empty($inquiry['order_number']))
                                    <tr>
                                        <td style="padding:10px 0;border-bottom:1px solid #1E293B;color:#94A3B8;font-weight:600;">Order Number:</td>
                                        <td style="padding:10px 0;border-bottom:1px solid #1E293B;color:#FFFFFF;font-family:monospace;font-weight:700;">{{ $inquiry['order_number'] }}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td style="padding:10px 0;color:#94A3B8;font-weight:600;">Topic / Subject:</td>
                                    <td style="padding:10px 0;color:#FFFFFF;font-weight:700;">{{ $inquiry['subject'] }}</td>
                                </tr>
                            </table>

                            <div style="margin-top:20px;padding:18px;background:#080C16;border:1px solid #1E293B;border-left:4px solid #E11D48;border-radius:12px;">
                                <p style="margin:0 0 8px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94A3B8;">Customer Message</p>
                                <p style="margin:0;font-size:14px;line-height:1.65;color:#F8FAFC;white-space:pre-line;">{{ $inquiry['message'] }}</p>
                            </div>

                            <div style="text-align:center;margin:24px 0 8px;">
                                <a href="mailto:{{ $inquiry['email'] }}?subject={{ rawurlencode('Re: '.$inquiry['subject'].' - '.$storeName) }}" style="display:inline-block;background-color:#10B981;color:#042F2E;font-weight:800;font-size:13.5px;padding:13px 28px;border-radius:10px;text-decoration:none;">
                                    Reply to {{ $inquiry['name'] }} &rarr;
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
