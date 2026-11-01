@php $store = (string) setting('store_name', 'SellMeTools'); @endphp
<x-page
    title="Privacy Policy"
    eyebrow="Data & Security"
    description="Learn how {{ $store }} protects your personal information, order history, PKR payment details and encrypted digital credentials."
    subtitle="How we collect, encrypt and safeguard your account and order information."
>
    <p>
        <strong>{{ $store }}</strong> (operated by <strong>AivraSol Softwares</strong>) respects your privacy and is committed to protecting the personal data you share with us when ordering digital tools and subscriptions.
    </p>

    <h2>1. Information We Collect</h2>
    <ul>
        <li><strong>Customer Contact Information:</strong> Your name, email address and phone / WhatsApp number provided at checkout, registration or via our support form.</li>
        <li><strong>Order &amp; Payment Verification Data:</strong> Purchased products, order totals, selected payment method, Transaction ID (TID), sender account number and optional payment receipt screenshots.</li>
        <li><strong>Technical &amp; Security Logs:</strong> Basic browser, device and IP address logs used strictly to prevent duplicate TID fraud, unauthorized login attempts and automated abuse.</li>
    </ul>

    <h2>2. How We Use Your Information</h2>
    <ul>
        <li>To reserve inventory, verify your local wallet or Binance payment and deliver your digital credentials on your private order page and by email.</li>
        <li>To maintain your customer order history so you can look up past purchases and credentials anytime.</li>
        <li>To provide customer support, warranty replacements and important order status notifications.</li>
    </ul>

    <h2>3. Credential Encryption &amp; Order Privacy</h2>
    <p>
        Delivered account credentials and license keys are protected behind unique cryptographic access tokens on your private order page or behind your authenticated customer account. We never store credit card numbers on our servers.
    </p>

    <h2>4. Data Sharing &amp; Third Parties</h2>
    <p>
        We do not sell, rent or trade your personal information to third-party marketers. Data is shared only with essential infrastructure or fulfillment providers when strictly necessary to complete your order or comply with applicable Pakistani law.
    </p>

    <h2>5. Your Privacy Rights</h2>
    <p>
        You may update your profile details inside your customer account at any time or contact us via our <a href="{{ route('pages.show', 'contact') }}">Contact &amp; Support page</a> to request access to or deletion of your personal account records.
    </p>
</x-page>
