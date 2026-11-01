@php $store = setting('store_name'); @endphp
<x-page title="Terms of Service" eyebrow="Policies">
    <p>By placing an order on {{ $store }} you agree to these terms.</p>
    <h2>1. Products</h2>
    <p>We sell digital products such as software subscriptions, licence keys, coupon links and account access. Product pages describe what you receive, its duration and its warranty. Please read them carefully before buying.</p>
    <h2>2. Orders & payment</h2>
    <ul>
        <li>Stock is reserved for a limited time after you place an order. Unpaid orders expire automatically.</li>
        <li>You must send the exact amount shown and submit a valid transaction ID. Each transaction ID can be used only once.</li>
        <li>Fraudulent or fake payment submissions will result in cancellation and may be reported.</li>
    </ul>
    <h2>3. Delivery</h2>
    <p>Products are delivered digitally on your order page after payment is verified, usually within minutes. In rare cases manual delivery may take longer.</p>
    <h2>4. Use of products</h2>
    <p>You are responsible for using products in accordance with the terms of the respective service provider. {{ $store }} is not affiliated with the brands whose products are listed; all trademarks belong to their owners.</p>
    <h2>5. Liability</h2>
    <p>Our liability is limited to the amount paid for the affected order. Warranty coverage is described per product and in our <a href="{{ route('pages.show', 'refund') }}">refund & warranty policy</a>.</p>
</x-page>

