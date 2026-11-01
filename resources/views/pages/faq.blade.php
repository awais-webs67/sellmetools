@php
    $faqs = [
        ['q' => 'How fast is digital tool delivery in Pakistan?', 'a' => 'In-stock subscriptions and credentials are automatically delivered on your private order page immediately after your payment Transaction ID (TID) is verified. Auto methods verify instantly; manual reviews are verified within 15 minutes.'],
        ['q' => 'Which payment methods do you accept?', 'a' => 'We accept all major Pakistani payment methods including Easypaisa, JazzCash, NayaPay, SadaPay, Bank Transfer / Raast, as well as Binance Pay (USDT TRC20).'],
        ['q' => 'Where do I find my Transaction ID (TID)?', 'a' => 'Your Transaction ID (TID / Trx ID / Ref#) is found in the confirmation SMS or in the transaction history receipt inside your wallet app (e.g. Easypaisa, JazzCash, SadaPay). For Binance, enter your Binance Order ID or TxID.'],
        ['q' => 'What happens if a product goes out of stock while I am paying?', 'a' => 'Your stock is automatically reserved for you as soon as your checkout order is generated, for the countdown period shown on your order page. No other user can claim your reserved item during this window.'],
        ['q' => 'How do I access my credentials later or on another device?', 'a' => 'You can access your order page at any time using our “Track Order” page with your Order ID and Email, or by signing into your Customer Account where all past credentials remain permanently saved.'],
        ['q' => 'What if a delivered account stops working within the warranty period?', 'a' => 'Every tool has its warranty terms stated on its product page. If you encounter any issue during the warranty period, simply message our support on WhatsApp with your Order ID, and we will promptly provide a replacement or resolution.'],
    ];

    $faqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => collect($faqs)->map(fn ($f) => [
            '@type' => 'Question',
            'name' => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
        ])->all(),
    ];
@endphp
@push('seo')
    <script type="application/ld+json">{!! json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush
<x-page title="Frequently Asked Questions" eyebrow="Help & FAQ">
    <p class="text-sm sm:text-base leading-relaxed mb-6">
        Find quick answers to common questions regarding purchasing, payment verification, and credentials delivery on {{ setting('store_name') }}.
    </p>

    <div class="space-y-3 not-prose">
        @foreach ($faqs as $faq)
            <details class="group card p-4 sm:p-5 border-2 border-line hover:border-brand/30 transition shadow-xs" @if ($loop->first) open @endif>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm sm:text-base font-bold text-ink">
                    <span>{{ $faq['q'] }}</span>
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-tint text-lg leading-none text-brand transition duration-200 group-open:rotate-45">+</span>
                </summary>
                <p class="mt-3 text-xs sm:text-sm leading-relaxed text-body border-t border-line/60 pt-3">{{ $faq['a'] }}</p>
            </details>
        @endforeach
    </div>
</x-page>
