@php
    $logoUrl = asset('images/logo.png');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password - {{ $storeName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0F172A;line-height:1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#F1F5F9;padding:32px 14px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:540px;background-color:#FFFFFF;border-radius:16px;border:1px solid #E2E8F0;overflow:hidden;box-shadow:0 10px 25px -5px rgba(15,23,42,0.06);">

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
                                        <span style="display:inline-block;background-color:#F1F5F9;border:1px solid #CBD5E1;color:#334155;font-size:11px;font-weight:700;padding:5px 12px;border-radius:999px;letter-spacing:0.3px;text-transform:uppercase;">
                                            Account Security
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Dark Hero Banner --}}
                    <tr>
                        <td style="background-color:#14171A;background-image:linear-gradient(135deg,#14171A 0%,#2B0912 100%);padding:28px;text-align:center;color:#FFFFFF;">
                            <p style="margin:0;font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#34D399;">
                                Password Reset Request
                            </p>
                            <h1 style="margin:8px 0 0;font-size:22px;font-weight:800;color:#FFFFFF;">
                                Reset Your {{ $storeName }} Password
                            </h1>
                        </td>
                    </tr>

                    {{-- Main Body --}}
                    <tr>
                        <td style="padding:30px 28px;">
                            <p style="margin:0 0 14px;font-size:15px;font-weight:600;color:#0F172A;">
                                Hello {{ $user->name }},
                            </p>
                            <p style="margin:0 0 24px;font-size:14px;color:#475569;line-height:1.65;">
                                We received a request to reset the password for your <strong>{{ $storeName }}</strong> account (<strong>{{ $user->email }}</strong>). Click the button below to choose a new password:
                            </p>

                            <div style="text-align:center;margin:26px 0;">
                                <a href="{{ $resetUrl }}" style="display:inline-block;background-color:#9B1B30;color:#FFFFFF;font-weight:700;font-size:14px;padding:14px 30px;border-radius:10px;text-decoration:none;box-shadow:0 4px 12px rgba(155,27,48,0.25);">
                                    Reset My Password →
                                </a>
                            </div>

                            <p style="margin:24px 0 0;font-size:12px;color:#64748B;line-height:1.6;">
                                This password reset link will expire in 60 minutes. If you did not request a password reset, no further action is required and your account remains safe.
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background-color:#F8FAFC;padding:18px 28px;border-top:1px solid #E2E8F0;text-align:center;font-size:11px;color:#94A3B8;">
                            &copy; {{ date('Y') }} {{ $storeName }} ({{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'sellmetools.com' }}). All rights reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
