<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>New Contact Inquiry - {{ $storeName }}</title>
</head>
<body style="margin:0;padding:24px;background-color:#F4F5F7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#14171A;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;margin:0 auto;background:#FFFFFF;border-radius:12px;overflow:hidden;border:1px solid #E4E6EB;">
        <tr>
            <td style="background:#120408;padding:24px 28px;color:#FFFFFF;">
                <p style="margin:0;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#ff8fa3;font-weight:700;">Customer Support Desk</p>
                <h1 style="margin:6px 0 0;font-size:22px;font-weight:700;color:#FFFFFF;">New Contact Message on {{ $storeName }}</h1>
            </td>
        </tr>
        <tr>
            <td style="padding:28px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:14px;">
                    <tr>
                        <td style="padding:8px 0;color:#6E757C;width:130px;font-weight:600;">Customer Name:</td>
                        <td style="padding:8px 0;color:#14171A;font-weight:700;">{{ $inquiry['name'] }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0;color:#6E757C;font-weight:600;">Reply Email:</td>
                        <td style="padding:8px 0;color:#9B1B30;font-weight:600;"><a href="mailto:{{ $inquiry['email'] }}" style="color:#9B1B30;text-decoration:none;">{{ $inquiry['email'] }}</a></td>
                    </tr>
                    @if (!empty($inquiry['phone']))
                        <tr>
                            <td style="padding:8px 0;color:#6E757C;font-weight:600;">WhatsApp / Phone:</td>
                            <td style="padding:8px 0;color:#14171A;">{{ $inquiry['phone'] }}</td>
                        </tr>
                    @endif
                    @if (!empty($inquiry['order_number']))
                        <tr>
                            <td style="padding:8px 0;color:#6E757C;font-weight:600;">Order Number:</td>
                            <td style="padding:8px 0;color:#14171A;font-family:monospace;font-weight:700;">{{ $inquiry['order_number'] }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td style="padding:8px 0;color:#6E757C;font-weight:600;">Topic / Subject:</td>
                        <td style="padding:8px 0;color:#14171A;font-weight:600;">{{ $inquiry['subject'] }}</td>
                    </tr>
                </table>

                <div style="margin-top:20px;padding:18px;background:#F8F9FA;border:1px solid #E4E6EB;border-radius:8px;">
                    <p style="margin:0 0 8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#6E757C;">Customer Message</p>
                    <p style="margin:0;font-size:14px;line-height:1.6;color:#14171A;white-space:pre-line;">{{ $inquiry['message'] }}</p>
                </div>

                <p style="margin:20px 0 0;font-size:12px;color:#6E757C;">
                    Tip: You can reply directly to this email to respond to <strong>{{ $inquiry['name'] }}</strong> ({{ $inquiry['email'] }}).
                </p>
            </td>
        </tr>
    </table>
</body>
</html>

