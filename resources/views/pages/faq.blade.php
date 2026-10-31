<x-page title="Frequently Asked Questions" eyebrow="Help">
    @foreach ([
        ['How fast is delivery?', 'Usually within a few minutes after you submit a valid transaction ID. Your product appears on the order page automatically.'],
        ['Which payment methods do you accept?', 'Easypaisa, JazzCash, NayaPay, SadaPay and Binance (USDT). Available methods are shown at checkout.'],
        ['Where do I find my transaction ID?', 'In the payment confirmation SMS or in the transaction history of your wallet app. Binance users can use the Pay order ID / TxID.'],
        ['What if the product goes out of stock while I pay?', 'Your stock is reserved for you as soon as you place the order, for the time shown on the order page.'],
        ['I lost my order page.', 'Use “Track order” with your order number and email, or log in to see all orders.'],
        ['My product stopped working.', 'Contact support with your order number within the warranty period shown on the product.'],
    ] as [$q, $a])
        <h2>{{ $q }}</h2>
        <p>{{ $a }}</p>
    @endforeach
</x-page>

