<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed &amp; Delivered</title>
</head>
<body style="margin: 0; padding: 0; background-color: #F8F9FB; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #14171A; line-height: 1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #F8F9FB; padding: 30px 15px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width: 600px; background-color: #FFFFFF; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.06); border: 1px solid #E6E8EB;" cellspacing="0" cellpadding="0">
                    {{-- Header Banner --}}
                    <tr>
                        <td style="background-color: #1F070E; background: linear-gradient(135deg, #2B0912 0%, #120408 100%); padding: 35px 30px; text-align: center; color: #FFFFFF;">
                            <h1 style="margin: 0; font-size: 24px; font-weight: 800; letter-spacing: -0.5px; color: #FFFFFF;">
                                {{ $storeName }}
                            </h1>
                            <div style="display: inline-block; background-color: #15803d; color: #FFFFFF; font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; text-transform: uppercase; margin-top: 12px; letter-spacing: 0.5px;">
                                ✓ Payment Confirmed &amp; Delivered
                            </div>
                            <h2 style="margin: 15px 0 5px; font-size: 20px; font-weight: 700; color: #FFFFFF;">
                                Your Credentials Are Ready!
                            </h2>
                            <p style="margin: 0; font-size: 13px; color: rgba(255,255,255,0.75);">
                                Order #{{ $order->number }} · {{ $order->created_at->format('d M Y, h:i A') }}
                            </p>
                        </td>
                    </tr>

                    {{-- Main Content --}}
                    <tr>
                        <td style="padding: 30px 25px;">
                            <p style="margin: 0 0 18px; font-size: 14px; color: #525860;">
                                Assalam o Alaikum! Your payment has been verified and your subscription credentials have been issued below.
                            </p>

                            {{-- Credentials Boxes --}}
                            @foreach ($deliveredItems as $item)
                                <div style="margin-bottom: 20px; border: 1px solid #E6E8EB; border-radius: 12px; overflow: hidden;">
                                    <div style="background-color: #F8F9FB; padding: 12px 16px; border-bottom: 1px solid #E6E8EB; font-weight: 700; font-size: 14px; color: #14171A;">
                                        📦 {{ $item->product_name }} (x{{ $item->quantity }})
                                    </div>
                                    <div style="background-color: #120408; padding: 16px; color: #4ade80; font-family: Courier, monospace; font-size: 13px; word-break: break-all; white-space: pre-wrap; line-height: 1.5;">
@foreach ($item->deliveries as $idx => $del)
{{ trim($del->content) }}@if (! $loop->last)

---
@endif
@endforeach
                                    </div>
                                </div>
                            @endforeach

                            {{-- Action Button --}}
                            <div style="text-align: center; margin: 30px 0 20px;">
                                <a href="{{ $orderUrl }}" style="display: inline-block; background-color: #8A0F27; color: #FFFFFF; font-weight: 700; font-size: 14px; padding: 14px 28px; border-radius: 10px; text-decoration: none; box-shadow: 0 4px 10px rgba(155,27,48,0.25);">
                                    View Live Order Page &amp; Receipt →
                                </a>
                            </div>

                            <p style="margin: 20px 0 0; font-size: 12px; color: #6E7681; text-align: center;">
                                Keep this email safe. Your credentials are also permanently saved in your Customer Panel.
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background-color: #F8F9FB; padding: 20px; text-align: center; font-size: 11px; color: #6E7681; border-top: 1px solid #E6E8EB;">
                            <p style="margin: 0 0 5px;">&copy; {{ date('Y') }} {{ $storeName }}. A Project by AivraSol Softwares.</p>
                            <p style="margin: 0;">For warranty or questions, reply to this email or contact support on WhatsApp.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

