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
    <title>Reset Your Password - {{ $storeName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0F172A;line-height:1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#F1F5F9;padding:32px 14px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:540px;background-color:#FFFFFF;border-radius:16px;border:1px solid #E2E8F0;overflow:hidden;box-shadow:0 4px 12px rgba(15,23,42,0.05);">

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
                                        <span style="display:inline-block;background-color:#F1F5F9;border:1px solid #CBD5E1;color:#334155;font-size:11px;font-weight:700;padding:5px 12px;border-radius:999px;">
                                            Account Security
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Clean Light Title Section --}}
                    <tr>
                        <td style="background-color:#F8FAFC;padding:26px 28px;text-align:center;border-bottom:1px solid #E2E8F0;">
                            <p style="margin:0;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:#64748B;">
                                Password Reset Request
                            </p>
                            <h1 style="margin:6px 0 0;font-size:21px;font-weight:800;color:#0F172A;">
                                Reset Your {{ $storeName }} Password
                            </h1>
                        </td>
                    </tr>

                    {{-- Main Body --}}
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 14px;font-size:15px;font-weight:600;color:#0F172A;">
                                Hello {{ $user->name }},
                            </p>
                            <p style="margin:0 0 24px;font-size:14px;color:#475569;line-height:1.65;">
                                We received a request to reset the password for your <strong>{{ $storeName }}</strong> account (<strong>{{ $user->email }}</strong>). Click the button below to choose a new password:
                            </p>

                            <div style="text-align:center;margin:26px 0;">
                                <a href="{{ $resetUrl }}" style="display:inline-block;background-color:#0F172A;color:#FFFFFF;font-weight:700;font-size:14px;padding:13px 28px;border-radius:10px;text-decoration:none;">
                                    Reset My Password
                                </a>
                            </div>

                            <p style="margin:24px 0 0;font-size:12px;color:#64748B;line-height:1.6;">
                                This password reset link will expire in 60 minutes. If you did not request a password reset, no further action is required and your account remains safe.
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background-color:#F8FAFC;padding:18px 28px;border-top:1px solid #E2E8F0;text-align:center;font-size:11px;color:#64748B;">
                            &copy; {{ date('Y') }} {{ $storeName }} ({{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'sellmetools.com' }}). All rights reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
