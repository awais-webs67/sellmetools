{{ $storeName }} - Password Reset Request

Hello {{ $user->name }},

We received a request to reset the password for your {{ $storeName }} account ({{ $user->email }}).

Use the link below to choose a new password (valid for 60 minutes):
{{ $resetUrl }}

If you did not request a password reset, you can safely ignore this email.

Thank you,
{{ $storeName }} Support Team
{{ url('/') }}

