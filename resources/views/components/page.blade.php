@props(['title', 'eyebrow' => 'Information'])
<x-layouts.store :title="$title">
    <header class="border-b border-line bg-paper">
        <div class="container-x">
            <div class="mx-auto max-w-3xl py-12 sm:py-16">
                <nav class="text-sm text-muted" aria-label="Breadcrumb">
                    <ol class="flex items-center gap-2">
                        <li><a href="{{ route('home') }}" class="transition hover:text-ink">Home</a></li>
                        <li aria-hidden="true">/</li>
                        <li class="text-ink" aria-current="page">{{ $eyebrow }}</li>
                    </ol>
                </nav>
                <h1 class="display mt-4 text-4xl sm:text-5xl">{{ $title }}</h1>
                <p class="mt-3 text-sm text-muted">Last updated {{ now()->format('F Y') }}</p>
            </div>
        </div>
    </header>

    <div class="container-x py-12 sm:py-16">
        <div class="mx-auto max-w-3xl">
            <div class="prose-store max-w-none">
                {{ $slot }}
            </div>

            <div class="mt-14 flex flex-col gap-4 rounded-2xl border border-line bg-white p-6 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="font-semibold text-ink">Still have a question?</p>
                    <p class="mt-0.5 text-sm text-muted">Our support team usually replies within a few minutes.</p>
                </div>
                @if ($wa = admin_whatsapp_number())
                    <a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener" class="btn btn-dark shrink-0">Message us on WhatsApp</a>
                @else
                    <a href="{{ route('pages.show', 'contact') }}" class="btn btn-dark shrink-0">Contact support</a>
                @endif
            </div>
        </div>
    </div>
</x-layouts.store>
