Thank You for Contacting {{ $storeName }}

Hello {{ $inquiry['name'] }},

Thank you for reaching out to {{ $storeName }}. We have received your message and our support team will reply to you shortly at {{ $inquiry['email'] }}.

Your Inquiry Summary:
Subject: {{ $inquiry['subject'] }}
@if (!empty($inquiry['order_number']))
Order Number: #{{ $inquiry['order_number'] }}
@endif

Message:
----------------------------------------
{{ $inquiry['message'] }}
----------------------------------------

Thank you,
{{ $storeName }} Support Team
{{ url('/') }}

