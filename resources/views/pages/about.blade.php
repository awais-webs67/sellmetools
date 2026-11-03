@php
    $store = (string) setting('store_name', 'SellMeTools');
@endphp
<x-page
    title="About {{ $store }} — AI Tools & Digital Subscriptions"
    eyebrow="About Our Store"
    description="Learn how {{ $store }} delivers genuine AI tools, creative software, cloud accounts and digital subscriptions in Pakistan at local PKR prices via Easypaisa, JazzCash, NayaPay and Binance."
    subtitle="Built for Pakistani freelancers, students, creators and software teams who want instant, verified access to global digital tools in local rupees."
>
    <div class="not-prose grid gap-4 sm:grid-cols-3 mb-8">
        @foreach ([
            ['100% Local PKR', 'Pay via Easypaisa, JazzCash, NayaPay, SadaPay, Raast or Binance USDT.'],
            ['Instant Delivery', 'Automated order page and email delivery right after verification.'],
            ['Clear Warranty', 'Transparent Full Warranty (FW) and Login Warranty options on every tool.'],
        ] as [$statTitle, $statDesc])
            <div class="rounded-xl border border-line bg-grey-soft/60 p-4">
                <p class="display text-2xl text-brand">{{ $statTitle }}</p>
                <p class="mt-1 text-xs sm:text-sm leading-relaxed text-body">{{ $statDesc }}</p>
            </div>
        @endforeach
    </div>

    <h2>Who We Are</h2>
    <p>
        <strong>{{ $store }}</strong> (a digital commerce project by <a href="https://aivrasol.com" target="_blank" rel="noopener"><strong>AivraSol Softwares</strong></a>) is a dedicated Pakistani marketplace for premium AI tools, creative software, productivity suites, streaming memberships and cloud accounts. We started {{ $store }} to solve one of the most frustrating challenges faced by professionals and students across Pakistan: paying for international USD subscriptions when local bank cards decline cross-border recurring charges or add heavy withholding and foreign exchange taxes.
    </p>

    <h2>What You Can Buy on {{ $store }}</h2>
    <p>
        Our catalog covers a wide range of verified digital tools and subscriptions that power modern work, study and digital creation in Pakistan:
    </p>
    <ul>
        <li><strong>AI Assistants &amp; Coding Tools:</strong> Premium AI research assistants, coding companions, conversational AI models and workflow automation tools for software engineers, researchers, copywriters and students.</li>
        <li><strong>Graphic Design &amp; Video Editing Suites:</strong> Team invites, private upgrades and creative asset memberships for video editors, graphic designers, social media managers and agencies.</li>
        <li><strong>Productivity, Academic &amp; SEO Tools:</strong> Office productivity suites, writing and grammar assistants, academic plagiarism checkers and digital marketing tools.</li>
        <li><strong>Entertainment &amp; Cloud Accounts:</strong> Verified streaming memberships, ready email accounts and developer cloud resources with clear usage rules.</li>
    </ul>

    <h2>How Our Automated Order &amp; Delivery System Works</h2>
    <p>
        Unlike informal sellers on social media where buyers wait hours for a manual reply, {{ $store }} runs on a purpose-built automated inventory and checkout engine:
    </p>
    <ol>
        <li><strong>Live Stock Reservation:</strong> Every product card displays real-time stock availability. When you start checkout, your item is reserved for your order window so it cannot be oversold.</li>
        <li><strong>Local Pakistani Wallet Checkout:</strong> Pay the exact PKR total using Easypaisa, JazzCash, NayaPay, SadaPay, local bank transfer / Raast, or USDT via Binance Pay.</li>
        <li><strong>Unique Transaction ID Protection:</strong> Submit your payment Transaction ID (TID) on your order page. Every TID is validated against duplicate use.</li>
        <li><strong>Private Order Page &amp; Email Vault:</strong> Once verified, your login credentials, activation link or license key appear immediately on your private order page and are sent to your email. Registered customers can also filter and search their entire order history by date inside their account dashboard.</li>
    </ol>

    <h2>Our Commitment to Transparency &amp; Warranty</h2>
    <p>
        We believe you should know exactly what type of subscription you are buying before you pay. Each product page states whether it is a private account, an email invite or a shared profile, along with its exact warranty term (such as Full Subscription Warranty or First-Login Warranty). If you ever need assistance within your covered warranty period, our support team is available via WhatsApp and our online helpdesk.
    </p>

    <div class="not-prose mt-8 flex flex-wrap items-center gap-3 border-t border-line pt-6">
        <a href="{{ route('shop') }}" class="btn btn-primary px-6 py-3 text-sm font-bold">Browse All Subscriptions →</a>
        <a href="{{ route('pages.show', 'contact') }}" class="btn btn-outline px-6 py-3 text-sm font-semibold">Contact Our Support Team</a>
    </div>
</x-page>

