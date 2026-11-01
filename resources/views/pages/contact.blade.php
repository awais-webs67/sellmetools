@php
    $store = (string) setting('store_name', 'SellMeTools');
    $wa = setting('support_whatsapp');
    $tg = setting('support_telegram');
    $email = setting('support_email') ?: setting('mail_from_address');
@endphp
<x-page
    title="Contact {{ $store }} & 24/7 Customer Support"
    eyebrow="Customer Support Desk"
    description="Get fast support for your {{ $store }} orders, warranty replacements, PKR payment verification or bulk reseller inquiries via WhatsApp, Telegram or our direct email contact form."
    subtitle="Need help with an existing order, replacement credentials or choosing the right plan? Send us a message below or chat with us on WhatsApp."
>
    @if (session('contact_sent'))
        <div class="not-prose mb-6 rounded-xl border border-success/30 bg-success-tint p-4 text-sm font-semibold text-success" role="status">
            ✓ {{ session('contact_sent') }}
        </div>
    @endif

    <p class="text-sm sm:text-base leading-relaxed">
        Our support team helps customers across Pakistan with payment verification, subscription activation and warranty replacements. If you are writing about an existing purchase, please include your <strong>Order ID (for example SMT-XXXXXX)</strong> so we can locate your order immediately.
    </p>

    {{-- Direct Contact Channels --}}
    <div class="not-prose mt-6 grid gap-4 sm:grid-cols-3">
        @if ($wa)
            <a href="https://wa.me/{{ preg_replace('/\D/', '', $wa) }}" target="_blank" rel="noopener" class="card p-4 sm:p-5 no-underline hover:border-brand hover:shadow-md transition active:scale-[0.98] group bg-white border border-line">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#25D366]/10 text-[#25D366] group-hover:bg-[#25D366] group-hover:text-white transition">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21c5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2Zm5.8 14.03c-.25.69-1.45 1.32-2 1.37-.51.05-1 .24-3.36-.7-2.84-1.12-4.63-4.03-4.77-4.22-.14-.19-1.14-1.52-1.14-2.9 0-1.38.72-2.06.98-2.34.25-.28.55-.35.74-.35l.53.01c.17 0 .4-.06.62.48.25.6.84 2.07.92 2.22.07.15.12.32.02.51-.1.19-.15.32-.29.49-.15.17-.31.38-.44.51-.15.15-.3.3-.13.6.17.29.77 1.27 1.65 2.06 1.14 1.01 2.09 1.33 2.39 1.48.29.15.47.12.64-.07.17-.2.74-.86.94-1.16.2-.29.39-.24.66-.15.27.1 1.73.82 2.02.97.3.15.5.22.57.34.07.12.07.71-.18 1.4Z"/></svg>
                </div>
                <p class="font-bold text-ink mt-3 text-sm">WhatsApp Live Chat</p>
                <p class="mt-0.5 text-xs text-muted font-mono">{{ $wa }}</p>
                <span class="mt-2.5 inline-flex items-center text-xs font-bold text-brand group-hover:translate-x-0.5 transition">Start WhatsApp Chat →</span>
            </a>
        @endif

        @if ($tg)
            <a href="https://t.me/{{ ltrim($tg, '@') }}" target="_blank" rel="noopener" class="card p-4 sm:p-5 no-underline hover:border-brand hover:shadow-md transition active:scale-[0.98] group bg-white border border-line">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#229ED9]/10 text-[#229ED9] group-hover:bg-[#229ED9] group-hover:text-white transition">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 0 0-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.75-.55 2.92-1.27 4.86-2.11 5.83-2.52 2.77-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/></svg>
                </div>
                <p class="font-bold text-ink mt-3 text-sm">Telegram Support</p>
                <p class="mt-0.5 text-xs text-muted font-mono">{{ '@'.ltrim($tg, '@') }}</p>
                <span class="mt-2.5 inline-flex items-center text-xs font-bold text-brand group-hover:translate-x-0.5 transition">Message on Telegram →</span>
            </a>
        @endif

        @if ($email)
            <a href="mailto:{{ $email }}" class="card p-4 sm:p-5 no-underline hover:border-brand hover:shadow-md transition active:scale-[0.98] group bg-white border border-line">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand/10 text-brand group-hover:bg-brand group-hover:text-white transition">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </div>
                <p class="font-bold text-ink mt-3 text-sm">Direct Support Email</p>
                <p class="mt-0.5 text-xs text-muted font-mono truncate">{{ $email }}</p>
                <span class="mt-2.5 inline-flex items-center text-xs font-bold text-brand group-hover:translate-x-0.5 transition">Send Direct Email →</span>
            </a>
        @endif
    </div>

    {{-- Working SMTP Contact Form --}}
    <div class="not-prose mt-8 rounded-2xl border border-line bg-grey-soft/50 p-5 sm:p-7">
        <div class="mb-5">
            <span class="eyebrow">Send an Email Ticket</span>
            <h2 class="display mt-1 text-2xl sm:text-3xl text-ink">Send Us a Direct Message</h2>
            <p class="mt-1 text-xs sm:text-sm text-muted">
                Fill out the form below and our system will deliver your inquiry straight to our support inbox. We reply directly to your email address.
            </p>
        </div>

        <form action="{{ route('pages.contact.submit') }}" method="post" class="space-y-4">
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="contact-name" class="label">Your Full Name *</label>
                    <input id="contact-name" type="text" name="name" value="{{ old('name', auth()->user()?->name) }}" required maxlength="100" placeholder="e.g. Ali Raza" class="input">
                    @error('name')
                        <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="contact-email" class="label">Your Email Address *</label>
                    <input id="contact-email" type="email" name="email" value="{{ old('email', auth()->user()?->email) }}" required maxlength="150" placeholder="you@example.com" class="input">
                    @error('email')
                        <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="contact-phone" class="label">WhatsApp / Phone (Optional)</label>
                    <input id="contact-phone" type="text" name="phone" value="{{ old('phone', auth()->user()?->phone) }}" maxlength="35" placeholder="0300-1234567" class="input">
                    @error('phone')
                        <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="contact-order" class="label">Order Number (Optional)</label>
                    <input id="contact-order" type="text" name="order_number" value="{{ old('order_number', request('order')) }}" maxlength="40" placeholder="SMT-XXXXXX" class="input font-mono">
                    @error('order_number')
                        <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="contact-subject" class="label">Topic / Subject *</label>
                    <select id="contact-subject" name="subject" required class="input">
                        @foreach ([
                            'Order & Payment Verification',
                            'Warranty / Replacement Request',
                            'Pre-Sale Product Question',
                            'Bulk / Reseller Partnership',
                            'Other Support Inquiry',
                        ] as $topic)
                            <option value="{{ $topic }}" @selected(old('subject') === $topic)>{{ $topic }}</option>
                        @endforeach
                    </select>
                    @error('subject')
                        <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div>
                <label for="contact-message" class="label">Your Message *</label>
                <textarea id="contact-message" name="message" rows="4" required minlength="10" maxlength="2500" placeholder="Write your question, Transaction ID details or the subscription plan you need help with..." class="input">{{ old('message') }}</textarea>
                @error('message')
                    <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between pt-1">
                <p class="text-xs text-muted">
                    Typical email response time: <strong>30 minutes to 2 hours</strong> during Pakistan working hours (10 AM to 1 AM PKT).
                </p>
                <button type="submit" class="btn btn-primary px-6 py-3 text-sm font-bold shrink-0">
                    Send Message to Support →
                </button>
            </div>
        </form>
    </div>

    <div class="not-prose mt-8 rounded-xl bg-white p-5 border border-line">
        <h3 class="text-sm font-bold text-ink">How to Get the Fastest Resolution</h3>
        <ul class="mt-2.5 space-y-1.5 text-xs sm:text-sm text-body">
            <li>• <strong>Payment Verification:</strong> Make sure you have submitted your Transaction ID (TID) and sender number directly on your private order page first.</li>
            <li>• <strong>Warranty Claim:</strong> Include your Order ID (`SMT-XXXXXX`) and a brief description or screenshot of the login error so our team can issue a fix or replacement immediately.</li>
            <li>• <strong>Reseller &amp; Agency Bulk Orders:</strong> Mention the tools and monthly quantities you need for custom wholesale PKR pricing.</li>
        </ul>
    </div>
</x-page>
