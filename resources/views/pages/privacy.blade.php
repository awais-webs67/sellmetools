@php $store = setting('store_name'); @endphp
<x-page title="Privacy Policy" eyebrow="Policies">
    <p>{{ $store }} respects your privacy. This policy explains what we collect and why.</p>
    <h2>What we collect</h2>
    <ul>
        <li>Contact details you provide: email, name and phone number.</li>
        <li>Order details: products, amounts, payment method, transaction ID, sender account number and optional payment screenshot.</li>
        <li>Technical data: IP address and basic logs used for fraud prevention and security.</li>
    </ul>
    <h2>How we use it</h2>
    <ul>
        <li>To process, verify and deliver your orders.</li>
        <li>To provide support and handle warranty requests.</li>
        <li>To prevent fraud and abuse.</li>
    </ul>
    <h2>Security</h2>
    <p>Delivered credentials and stock are stored encrypted. Access to your order page requires a private link or your account login.</p>
    <h2>Sharing</h2>
    <p>We do not sell your data. Information is shared only with payment providers and fulfilment partners when strictly required to complete your order, or when required by law.</p>
    <h2>Your rights</h2>
    <p>You may request a copy or deletion of your personal data by contacting us.</p>
</x-page>

