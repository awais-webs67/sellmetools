@php $store = (string) setting('store_name', 'SellMeTools'); @endphp
<x-page
    title="Terms of Service"
    eyebrow="Legal & Store Terms"
    description="Read the official Terms of Service for purchasing digital tools, AI subscriptions and software accounts on {{ $store }} in Pakistan."
    subtitle="Clear, straightforward terms governing orders, local PKR payments, automated credential delivery and acceptable account usage."
>
    <p>
        Welcome to <strong>{{ $store }}</strong>. By creating an account or placing an order on our website, you agree to the terms below. Please read each product description carefully before completing checkout.
    </p>

    <h2>1. Digital Products &amp; Plan Descriptions</h2>
    <p>
        {{ $store }} provides digital subscriptions, software license keys, official team invitations, redeemable activation links and pre-configured digital accounts. Every product listing specifies:
    </p>
    <ul>
        <li>The exact plan type (private account, email invite, shared profile or redeemable link).</li>
        <li>The subscription duration (for example 1 month, 6 months or 1 year).</li>
        <li>The applicable warranty term (such as Full Warranty, Login Warranty or No Warranty).</li>
    </ul>

    <h2>2. Orders, Stock Reservation &amp; Payment Verification</h2>
    <ul>
        <li><strong>Stock Reservation:</strong> When you initiate checkout, available inventory is reserved for your order until the payment timer expires. Unpaid orders expire automatically and release their reserved stock back to the catalog.</li>
        <li><strong>Exact Payment Amount:</strong> You must send the exact PKR or USDT amount displayed on your order page using one of our active payment methods (Easypaisa, JazzCash, NayaPay, SadaPay, Bank Transfer / Raast or Binance Pay).</li>
        <li><strong>Single-Use Transaction ID (TID):</strong> Each payment Transaction ID can only be used once. Submitting altered screenshots, fake TIDs or recycled receipts will result in immediate order cancellation and account suspension.</li>
    </ul>

    <h2>3. Automated Digital Delivery</h2>
    <p>
        In-stock items are delivered digitally on your private order page and sent to your registered email address as soon as payment verification completes. You can also view all delivered credentials anytime in your customer dashboard.
    </p>

    <h2>4. Acceptable Account Usage Rules</h2>
    <ul>
        <li>For shared or profile-based subscriptions, you must only use your assigned profile and must not change the primary account email, password, billing settings or security credentials unless explicitly instructed on the product page.</li>
        <li>Violating the usage instructions stated on a product page voids the product warranty.</li>
        <li>{{ $store }} is an independent digital marketplace and is not affiliated with or endorsed by third-party software brands listed on the site. All brand names and trademarks belong to their respective owners.</li>
    </ul>

    <h2>5. Limitation of Liability &amp; Warranty Coverage</h2>
    <p>
        Our maximum liability for any order is strictly limited to replacement of the affected digital item or a refund up to the original PKR amount paid for that order, in accordance with our <a href="{{ route('pages.show', 'refund') }}">Refund &amp; Warranty Policy</a>.
    </p>
</x-page>
