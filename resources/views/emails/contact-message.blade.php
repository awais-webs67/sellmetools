@php
    $logoUrl = asset('images/logo.png');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Contact Inquiry - {{ $storeName }}</title>
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
                                        <span style="display:inline-block;background-color:#FEF2F2;border:1px solid #FECACA;color:#991B1B;font-size:11px;font-weight:700;padding:5px 12px;border-radius:999px;letter-spacing:0.3px;text-transform:uppercase;">
                                            ● New Support Ticket
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Dark Hero Banner --}}
                    <tr>
                        <td style="background-color:#14171A;background-image:linear-gradient(135deg,#14171A 0%,#2B0912 100%);padding:26px 28px;color:#FFFFFF;">
                            <p style="margin:0;font-size:11px;letter-spacing:1.4px;text-transform:uppercase;color:#34D399;font-weight:700;">Customer Support Desk</p>
                            <h1 style="margin:6px 0 0;font-size:21px;font-weight:800;color:#FFFFFF;">New Contact Message on {{ $storeName }}</h1>
                        </td>
                    </tr>

                    {{-- Main Body --}}
                    <tr>
                        <td style="padding:28px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:14px;">
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #F1F5F9;color:#64748B;width:140px;font-weight:600;">Customer Name:</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #F1F5F9;color:#0F172A;font-weight:700;">{{ $inquiry['name'] }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #F1F5F9;color:#64748B;font-weight:600;">Reply Email:</td>
                                    <td style="padding:10px 0;border-bottom:1px solid #F1F5F9;color:#9B1B30;font-weight:700;">
                                        <a href="mailto:{{ $inquiry['email'] }}" style="color:#9B1B30;text-decoration:none;">{{ $inquiry['email'] }}</a>
                                    </td>
                                </tr>
                                @if (!empty($inquiry['phone']))
                                    <tr>
                                        <td style="padding:10px 0;border-bottom:1px solid #F1F5F9;color:#64748B;font-weight:600;">WhatsApp / Phone:</td>
                                        <td style="padding:10px 0;border-bottom:1px solid #F1F5F9;color:#0F172A;font-weight:600;">{{ $inquiry['phone'] }}</td>
                                    </tr>
                                @endif
                                @if (!empty($inquiry['order_number']))
                                    <tr>
                                        <td style="padding:10px 0;border-bottom:1px solid #F1F5F9;color:#64748B;font-weight:600;">Order Number:</td>
                                        <td style="padding:10px 0;border-bottom:1px solid #F1F5F9;color:#0F172A;font-family:monospace;font-weight:700;">{{ $inquiry['order_number'] }}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td style="padding:10px 0;color:#64748B;font-weight:600;">Topic / Subject:</td>
                                    <td style="padding:10px 0;color:#0F172A;font-weight:700;">{{ $inquiry['subject'] }}</td>
                                </tr>
                            </table>

                            <div style="margin-top:20px;padding:18px;background:#F8FAFC;border:1px solid #CBD5E1;border-left:4px solid #9B1B30;border-radius:10px;">
                                <p style="margin:0 0 8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#64748B;">Customer Message</p>
                                <p style="margin:0;font-size:14px;line-height:1.65;color:#0F172A;white-space:pre-line;">{{ $inquiry['message'] }}</p>
                            </div>

                            <div style="text-align:center;margin:24px 0 8px;">
                                <a href="mailto:{{ $inquiry['email'] }}?subject={{ rawurlencode('Re: '.$inquiry['subject'].' - '.$storeName) }}" style="display:inline-block;background-color:#9B1B30;color:#FFFFFF;font-weight:700;font-size:13px;padding:12px 26px;border-radius:10px;text-decoration:none;">
                                    Reply to {{ $inquiry['name'] }} →
                                </a>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
