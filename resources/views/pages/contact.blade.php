<x-page title="Contact & Customer Support" eyebrow="24/7 Helpdesk">
    <p class="text-sm sm:text-base leading-relaxed">
        Need assistance with an order, replacement credentials, or a custom subscription plan? Our support team replies within minutes during business hours. For existing purchases, always include your <strong>Order ID (SMT-XXXXXX)</strong> for immediate verification.
    </p>

    <div class="not-prose mt-8 grid gap-4 sm:grid-cols-3">
        @if ($wa = setting('support_whatsapp'))
            <a href="https://wa.me/{{ preg_replace('/\D/', '', $wa) }}" target="_blank" rel="noopener" class="card p-5 no-underline hover:border-brand hover:shadow-md transition active:scale-[0.98] group bg-white border-2 border-line">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#25D366]/10 text-[#25D366] group-hover:bg-[#25D366] group-hover:text-white transition">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21c5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2Zm5.8 14.03c-.25.69-1.45 1.32-2 1.37-.51.05-1 .24-3.36-.7-2.84-1.12-4.63-4.03-4.77-4.22-.14-.19-1.14-1.52-1.14-2.9 0-1.38.72-2.06.98-2.34.25-.28.55-.35.74-.35l.53.01c.17 0 .4-.06.62.48.25.6.84 2.07.92 2.22.07.15.12.32.02.51-.1.19-.15.32-.29.49-.15.17-.31.38-.44.51-.15.15-.3.3-.13.6.17.29.77 1.27 1.65 2.06 1.14 1.01 2.09 1.33 2.39 1.48.29.15.47.12.64-.07.17-.2.74-.86.94-1.16.2-.29.39-.24.66-.15.27.1 1.73.82 2.02.97.3.15.5.22.57.34.07.12.07.71-.18 1.4Z"/></svg>
                </div>
                <p class="font-bold text-ink mt-3">WhatsApp Live Chat</p>
                <p class="mt-1 text-xs text-muted font-mono">{{ $wa }}</p>
                <span class="mt-3 inline-flex items-center text-xs font-semibold text-brand group-hover:translate-x-1 transition">Open Chat →</span>
            </a>
        @endif

        @if ($tg = setting('support_telegram'))
            <a href="https://t.me/{{ ltrim($tg, '@') }}" target="_blank" rel="noopener" class="card p-5 no-underline hover:border-brand hover:shadow-md transition active:scale-[0.98] group bg-white border-2 border-line">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#229ED9]/10 text-[#229ED9] group-hover:bg-[#229ED9] group-hover:text-white transition">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 0 0-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.75-.55 2.92-1.27 4.86-2.11 5.83-2.52 2.77-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/></svg>
                </div>
                <p class="font-bold text-ink mt-3">Telegram Support</p>
                <p class="mt-1 text-xs text-muted font-mono">{{ '@'.ltrim($tg, '@') }}</p>
                <span class="mt-3 inline-flex items-center text-xs font-semibold text-brand group-hover:translate-x-1 transition">Open Telegram →</span>
            </a>
        @endif

        @if ($email = setting('support_email'))
            <a href="mailto:{{ $email }}" class="card p-5 no-underline hover:border-brand hover:shadow-md transition active:scale-[0.98] group bg-white border-2 border-line">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand/10 text-brand group-hover:bg-brand group-hover:text-white transition">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </div>
                <p class="font-bold text-ink mt-3">Direct Email</p>
                <p class="mt-1 text-xs text-muted font-mono truncate">{{ $email }}</p>
                <span class="mt-3 inline-flex items-center text-xs font-semibold text-brand group-hover:translate-x-1 transition">Send Email →</span>
            </a>
        @endif
    </div>

    @unless (setting('support_whatsapp') || setting('support_telegram') || setting('support_email'))
        <p class="mt-6 text-sm text-muted">Direct contact channels will be updated shortly.</p>
    @endunless

    <div class="mt-10 rounded-xl bg-grey-soft p-5 border border-line">
        <h3 class="text-sm font-bold text-ink">Typical Response Times</h3>
        <ul class="mt-2 space-y-1 text-xs text-body">
            <li>• <strong>WhatsApp:</strong> 5 to 15 minutes during standard Pakistan working hours (10 AM to 1 AM PKT).</li>
            <li>• <strong>Telegram:</strong> Under 30 minutes.</li>
            <li>• <strong>Email:</strong> Within 2 to 4 hours.</li>
        </ul>
    </div>
</x-page>
