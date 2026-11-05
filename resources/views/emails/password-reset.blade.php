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
    <title>Reset Your Password - {{ $storeName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#060911;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#F8FAFC;line-height:1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#060911;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background-color:#0D1322;border-radius:18px;border:1px solid #1E293B;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,0.6);">

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
                                        <span style="display:inline-block;background-color:#1E293B;border:1px solid #334155;color:#E2E8F0;font-size:11px;font-weight:700;padding:5px 12px;border-radius:999px;">
                                            Account Security
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Dark Title Banner --}}
                    <tr>
                        <td style="background-color:#111827;background-image:linear-gradient(180deg,#131C31 0%,#0D1322 100%);padding:28px 26px;text-align:center;border-bottom:1px solid #1E293B;">
                            <p style="margin:0;font-size:11px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:#F43F5E;">
                                Password Reset Request
                            </p>
                            <h1 style="margin:8px 0 0;font-size:22px;font-weight:800;color:#FFFFFF;">
                                Reset Your {{ $storeName }} Password
                            </h1>
                        </td>
                    </tr>

                    {{-- Main Body --}}
                    <tr>
                        <td style="padding:28px 26px;">
                            <p style="margin:0 0 14px;font-size:15px;font-weight:700;color:#FFFFFF;">
                                Hello {{ $user->name }},
                            </p>
                            <p style="margin:0 0 24px;font-size:14px;color:#CBD5E1;line-height:1.65;">
                                We received a request to reset the password for your <strong style="color:#FFFFFF;">{{ $storeName }}</strong> account (<strong style="color:#38BDF8;">{{ $user->email }}</strong>). Click the button below to choose a new password:
                            </p>

                            <div style="text-align:center;margin:28px 0;">
                                <a href="{{ $resetUrl }}" style="display:inline-block;background-color:#E11D48;color:#FFFFFF;font-weight:800;font-size:14px;padding:14px 30px;border-radius:10px;text-decoration:none;box-shadow:0 4px 14px rgba(225,29,72,0.35);">
                                    Reset My Password &rarr;
                                </a>
                            </div>

                            <div style="margin-top:24px;padding:14px 16px;background-color:#080C16;border:1px solid #1E293B;border-radius:10px;">
                                <p style="margin:0;font-size:12px;color:#94A3B8;line-height:1.6;">
                                    This password reset link will expire in 60 minutes. If you did not request a password reset, no further action is required and your account remains secure.
                                </p>
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
