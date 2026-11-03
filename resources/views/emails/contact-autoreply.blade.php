<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>We Received Your Message - {{ $storeName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#F4F6F8;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;line-height:1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#F4F6F8;padding:28px 14px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background-color:#FFFFFF;border-radius:12px;border:1px solid #E5E7EB;overflow:hidden;">
                    <tr>
                        <td style="padding:24px 28px;border-bottom:1px solid #E5E7EB;background-color:#111827;color:#FFFFFF;">
                            <p style="margin:0;font-size:12px;font-weight:600;letter-spacing:0.8px;text-transform:uppercase;color:#34D399;">Support Desk Confirmation</p>
                            <h1 style="margin:6px 0 0;font-size:20px;font-weight:700;color:#FFFFFF;">Thank You for Contacting {{ $storeName }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 14px;font-size:15px;color:#111827;">
                                Hello {{ $inquiry['name'] }},
                            </p>
                            <p style="margin:0 0 18px;font-size:14px;color:#374151;">
                                Thank you for reaching out to <strong>{{ $storeName }}</strong>. We have received your message and a member of our support team will reply to you shortly at <strong>{{ $inquiry['email'] }}</strong>.
                            </p>

                            <div style="margin:20px 0;padding:16px;background-color:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;">
                                <p style="margin:0 0 6px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;color:#6B7280;">
                                    Your Inquiry Summary
                                </p>
                                <p style="margin:0 0 8px;font-size:14px;font-weight:600;color:#111827;">
                                    Subject: {{ $inquiry['subject'] }}
                                    @if (!empty($inquiry['order_number']))
                                        · Order: #{{ $inquiry['order_number'] }}
                                    @endif
                                </p>
                                <p style="margin:0;font-size:13px;color:#4B5563;white-space:pre-line;">{{ $inquiry['message'] }}</p>
                            </div>

                            @php $wa = admin_whatsapp_number(); @endphp
                            @if ($wa !== '')
                                <p style="margin:18px 0 0;font-size:13px;color:#4B5563;">
                                    Need urgent assistance with an active order? You can also message our live support team directly on WhatsApp at <a href="https://wa.me/{{ $wa }}" style="color:#111827;font-weight:600;text-decoration:underline;">+{{ $wa }}</a>.
                                </p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#F9FAFB;padding:18px 28px;border-top:1px solid #E5E7EB;font-size:12px;color:#6B7280;text-align:center;">
                            <p style="margin:0;">&copy; {{ date('Y') }} {{ $storeName }} ({{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'sellmetools.com' }}). All rights reserved.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

