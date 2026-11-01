<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password</title>
</head>
<body style="margin: 0; padding: 0; background-color: #F8F9FB; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #14171A; line-height: 1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #F8F9FB; padding: 30px 15px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width: 520px; background-color: #FFFFFF; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.06); border: 1px solid #E6E8EB;" cellspacing="0" cellpadding="0">
                    <tr>
                        <td style="background-color: #1F070E; padding: 30px; text-align: center; color: #FFFFFF;">
                            <h1 style="margin: 0; font-size: 22px; font-weight: 800; color: #FFFFFF;">
                                {{ $storeName }}
                            </h1>
                            <p style="margin: 6px 0 0; font-size: 13px; color: rgba(255,255,255,0.7);">
                                Customer Password Reset Request
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 30px 25px;">
                            <p style="margin: 0 0 16px; font-size: 14px; color: #14171A; font-weight: 600;">
                                Hello {{ $user->name }},
                            </p>
                            <p style="margin: 0 0 24px; font-size: 14px; color: #525860;">
                                We received a request to reset the password for your {{ $storeName }} account (<strong>{{ $user->email }}</strong>). Click the button below to choose a new password:
                            </p>

                            <div style="text-align: center; margin: 25px 0;">
                                <a href="{{ $resetUrl }}" style="display: inline-block; background-color: #9B1B30; color: #FFFFFF; font-weight: 700; font-size: 14px; padding: 13px 28px; border-radius: 10px; text-decoration: none;">
                                    Reset My Password →
                                </a>
                            </div>

                            <p style="margin: 25px 0 0; font-size: 12px; color: #6E7681; line-height: 1.5;">
                                This password reset link will expire in 60 minutes. If you did not request a password reset, no further action is required and your account remains safe.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #F8F9FB; padding: 18px; text-align: center; font-size: 11px; color: #6E7681; border-top: 1px solid #E6E8EB;">
                            &copy; {{ date('Y') }} {{ $storeName }}. All rights reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
