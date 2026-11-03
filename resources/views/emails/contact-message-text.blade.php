New Contact Inquiry on {{ $storeName }}

Customer Name: {{ $inquiry['name'] }}
Reply Email: {{ $inquiry['email'] }}
@if (!empty($inquiry['phone']))
WhatsApp / Phone: {{ $inquiry['phone'] }}
@endif
@if (!empty($inquiry['order_number']))
Order Number: {{ $inquiry['order_number'] }}
@endif
Subject: {{ $inquiry['subject'] }}

Customer Message:
----------------------------------------
{{ $inquiry['message'] }}
----------------------------------------

You can reply directly to this email to respond to {{ $inquiry['name'] }} ({{ $inquiry['email'] }}).

