<x-page title="Contact & Support" eyebrow="Help">
    <p>We usually reply within minutes during business hours. Always include your <strong>order number</strong>.</p>
    <div class="not-prose mt-6 grid gap-4 sm:grid-cols-3">
        @if ($wa = setting('support_whatsapp'))
            <a href="https://wa.me/{{ preg_replace('/\D/', '', $wa) }}" target="_blank" rel="noopener" class="card p-5 no-underline hover:border-brand">
                <p class="font-semibold text-ink">WhatsApp</p><p class="mt-1 text-sm">{{ $wa }}</p>
            </a>
        @endif
        @if ($tg = setting('support_telegram'))
            <a href="https://t.me/{{ ltrim($tg, '@') }}" target="_blank" rel="noopener" class="card p-5 no-underline hover:border-brand">
                <p class="font-semibold text-ink">Telegram</p><p class="mt-1 text-sm">{{ '@'.ltrim($tg, '@') }}</p>
            </a>
        @endif
        @if ($email = setting('support_email'))
            <a href="mailto:{{ $email }}" class="card p-5 no-underline hover:border-brand">
                <p class="font-semibold text-ink">Email</p><p class="mt-1 text-sm">{{ $email }}</p>
            </a>
        @endif
    </div>
    @unless (setting('support_whatsapp') || setting('support_telegram') || setting('support_email'))
        <p>Support channels will be published here soon.</p>
    @endunless
</x-page>

