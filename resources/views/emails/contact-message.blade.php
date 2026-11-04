@php
    $logoUrl = asset('images/email-logo.png');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>New Contact Inquiry - {{ $storeName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0F172A;line-height:1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#F1F5F9;padding:32px 14px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background-color:#FFFFFF;border-radius:16px;border:1px solid #E2E8F0;overflow:hidden;box-shadow:0 4px 12px rgba(15,23,42,0.05);">

                    {{-- Light Brand Logo Header --}}
                    <tr>
                        <td style="background-color:#FFFFFF;background-image:linear-gradient(#FFFFFF,#FFFFFF);padding:22px 28px;border-bottom:1px solid #E2E8F0;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="left" valign="middle">
                                        <a href="{{ url('/') }}" style="text-decoration:none;display:inline-block;background-color:#FFFFFF;background-image:linear-gradient(#FFFFFF,#FFFFFF);border-radius:8px;">
                                            <img src="{{ $logoUrl }}" alt="{{ $storeName }}" width="165" style="display:block;height:36px;width:auto;max-width:175px;border:0;border-radius:6px;">
                                        </a>
                                    </td>
                                    <td align="right" valign="middle">
                                        <span style="display:inline-block;background-color:#FEF2F2;border:1px solid #FECACA;color:#991B1B;font-size:11px;font-weight:700;padding:5px 12px;border-radius:999px;">
                                            New Support Ticket
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Clean Light Banner --}}
                    <tr>
                        <td style="background-color:#F8FAFC;padding:24px 28px;border-bottom:1px solid #E2E8F0;">
                            <p style="margin:0;font-size:11px;letter-spacing:1.2px;text-transform:uppercase;color:#64748B;font-weight:700;">Customer Support Desk</p>
                            <h1 style="margin:6px 0 0;font-size:20px;font-weight:800;color:#0F172A;">New Contact Message on {{ $storeName }}</h1>
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
                                    <td style="padding:10px 0;border-bottom:1px solid #F1F5F9;color:#0F172A;font-weight:700;">
                                        <a href="mailto:{{ $inquiry['email'] }}" style="color:#0F172A;text-decoration:underline;">{{ $inquiry['email'] }}</a>
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

                            <div style="margin-top:20px;padding:18px;background:#F8FAFC;border:1px solid #CBD5E1;border-left:4px solid #0F172A;border-radius:10px;">
                                <p style="margin:0 0 8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#64748B;">Customer Message</p>
                                <p style="margin:0;font-size:14px;line-height:1.65;color:#0F172A;white-space:pre-line;">{{ $inquiry['message'] }}</p>
                            </div>

                            <div style="text-align:center;margin:24px 0 8px;">
                                <a href="mailto:{{ $inquiry['email'] }}?subject={{ rawurlencode('Re: '.$inquiry['subject'].' - '.$storeName) }}" style="display:inline-block;background-color:#0F172A;color:#FFFFFF;font-weight:700;font-size:13px;padding:12px 26px;border-radius:10px;text-decoration:none;">
                                    Reply to {{ $inquiry['name'] }}
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
