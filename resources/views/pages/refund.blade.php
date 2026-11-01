@php $store = (string) setting('store_name', 'SellMeTools'); @endphp
<x-page
    title="Refund & Warranty Policy"
    eyebrow="Buyer Protection"
    description="Understand how {{ $store }} protects your digital subscription orders in Pakistan with clear Full Warranty (FW), Login Warranty and refund eligibility rules."
    subtitle="Transparent replacement and refund rules so you always know how your purchase is covered before you pay."
>
    <p>
        At <strong>{{ $store }}</strong>, every product page displays its exact warranty coverage before you buy. This policy explains how warranty replacements and refunds work across our catalog.
    </p>

    <h2>1. Understanding Product Warranty Types</h2>
    <ul>
        <li><strong>Full Warranty (FW):</strong> Your subscription is covered for the entire advertised duration (for example 27 to 30 days on a 1-month plan). If the account or invite stops working during that period while following the product rules, we provide a repair or replacement free of charge.</li>
        <li><strong>First-Login Warranty:</strong> Covers initial login verification within the timeframe stated on the product page (for example 1 hour to 24 hours after delivery). Once verified and logged in, ongoing platform changes are outside warranty coverage.</li>
        <li><strong>No Warranty (NW):</strong> Certain discounted bulk or promotional items are sold strictly on a no-warranty basis once valid credentials are delivered. This is always clearly labeled in the product title or description.</li>
    </ul>

    <h2>2. When You Are Eligible for a Replacement or Refund</h2>
    <ul>
        <li>The delivered credentials or activation link do not work upon first use and you report the issue within the stated warranty window with your Order ID (`SMT-XXXXXX`).</li>
        <li>A Full Warranty (FW) product experiences a subscription interruption during the covered period and our team is unable to restore or replace it within a reasonable time.</li>
        <li>Your payment was verified, but stock became unavailable before delivery could be completed.</li>
        <li>You accidentally sent a duplicate payment for the same order.</li>
    </ul>

    <h2>3. When Refunds or Replacements Do Not Apply</h2>
    <ul>
        <li>Issues reported after the product’s stated warranty period has expired.</li>
        <li>Products explicitly labeled as "No Warranty (NW)" after initial working delivery.</li>
        <li>Account lockouts or revocations caused by customer modifications (such as changing passwords, email addresses, billing profiles or sharing credentials publicly against product rules).</li>
        <li>Change-of-mind requests after valid digital credentials or license keys have already been revealed on your order page.</li>
    </ul>

    <h2>4. How Replacements &amp; Refunds Are Processed</h2>
    <p>
        To claim a warranty replacement or refund, contact our support team via <a href="{{ route('pages.show', 'contact') }}">our Contact &amp; Support page</a> or WhatsApp with your <strong>Order ID</strong> and a screenshot of the issue. Approved replacements are issued within a few hours, and approved refunds are returned to your original Pakistani wallet (Easypaisa, JazzCash, NayaPay, SadaPay, bank account or Binance) or credited to your customer wallet within 1 to 3 business days.
    </p>
</x-page>
