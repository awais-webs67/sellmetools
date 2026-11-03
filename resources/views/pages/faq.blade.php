@php
    $store = (string) setting('store_name', 'SellMeTools');

    $faqGroups = [
        'Ordering & Instant Delivery' => [
            [
                'q' => 'How fast are digital tools and subscriptions delivered in Pakistan?',
                'a' => 'In-stock products are delivered automatically on your private order page and sent to your email immediately after your payment Transaction ID (TID) is verified. Automated payment methods verify in seconds, while manual wallet transfers are reviewed within 5 to 15 minutes during working hours.',
            ],
            [
                'q' => 'What happens if a product goes out of stock while I am sending payment?',
                'a' => 'As soon as you click checkout and generate your order, the system reserves your stock for the countdown timer shown on your order page. No other buyer can claim your reserved item during that window.',
            ],
            [
                'q' => 'Where do I find my delivered login credentials or invite link later?',
                'a' => 'Your credentials remain saved on your private order page (accessible anytime via our Track Order page using your Order ID and email) and are also emailed to you. If you create a customer account on '.$store.', all of your orders stay organized by date in your dashboard.',
            ],
        ],
        'Pakistani Payments & Wallets (PKR & USDT)' => [
            [
                'q' => 'Which Pakistani payment methods do you accept?',
                'a' => 'You can pay in Pakistani Rupees (PKR) using Easypaisa, JazzCash, NayaPay, SadaPay, Raast and local bank transfer, or pay in USDT via Binance Pay. You do not need an international credit or debit card.',
            ],
            [
                'q' => 'Where can I find my Transaction ID (TID) to verify payment?',
                'a' => 'Your Transaction ID (TID / Trx ID / Reference Number) appears on the payment receipt inside your Easypaisa, JazzCash, NayaPay or SadaPay app and in the confirmation SMS from 3737 or 8558. For Binance Pay, enter your Binance Order ID.',
            ],
            [
                'q' => 'Can I use the same Transaction ID on two orders?',
                'a' => 'No. Every Transaction ID is permanently locked once submitted to prevent duplicate or fraudulent claims. Always send the exact PKR amount shown on your current order page.',
            ],
        ],
        'Subscription Plans, Warranty & Support' => [
            [
                'q' => 'What is the difference between Full Warranty (FW) and Login Warranty?',
                'a' => 'Full Warranty (FW) means your subscription is covered for the entire advertised duration (for example 1 month or 1 year) with free replacement or repair if an issue occurs. Login Warranty (or First-Login Warranty) covers the initial sign-in window stated on the product page.',
            ],
            [
                'q' => 'What should I do if my subscription stops working during the warranty period?',
                'a' => 'Keep the account details unchanged and contact our support team via WhatsApp or the Contact Us form with your Order ID (SMT-XXXXXX) and a screenshot of the error. Our team will resolve the issue or issue a replacement according to the product warranty.',
            ],
            [
                'q' => 'Do you offer bulk or wholesale pricing for Pakistani resellers and agencies?',
                'a' => 'Yes. Digital marketers, software houses and subscription resellers can apply for a Reseller account to access discounted wholesale rates and instant wallet-balance checkout.',
            ],
        ],
    ];

    $allFaqs = collect($faqGroups)->flatten(1)->values();

    $faqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $allFaqs->map(fn ($f) => [
            '@type' => 'Question',
            'name' => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
        ])->all(),
    ];
@endphp
@push('seo')
    <script type="application/ld+json">{!! json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush
<x-page
    title="FAQ & Help Guide — {{ $store }}"
    eyebrow="Help Center & FAQ"
    description="Find answers about buying AI tools, premium software and digital subscriptions in Pakistan, paying via Easypaisa or JazzCash, instant order delivery and warranty coverage."
    subtitle="Everything you need to know about ordering digital tools in PKR, verifying your Transaction ID and accessing your credentials."
>
    <p class="text-sm sm:text-base leading-relaxed mb-6">
        Browse the most common questions asked by customers across Pakistan about ordering AI tools and digital subscriptions on <strong>{{ $store }}</strong>.
    </p>

    <div class="not-prose space-y-8">
        @foreach ($faqGroups as $groupTitle => $items)
            <div>
                <h2 class="display text-2xl sm:text-3xl text-ink mb-3">{{ $groupTitle }}</h2>
                <div class="space-y-3">
                    @foreach ($items as $faq)
                        <details class="group card p-4 sm:p-5 border border-line hover:border-brand/35 transition" @if ($loop->parent->first && $loop->first) open @endif>
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm sm:text-base font-bold text-ink">
                                <span>{{ $faq['q'] }}</span>
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-tint text-lg leading-none text-brand transition duration-200 group-open:rotate-45">+</span>
                            </summary>
                            <p class="mt-3 text-xs sm:text-sm leading-relaxed text-body border-t border-line pt-3">{{ $faq['a'] }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</x-page>
