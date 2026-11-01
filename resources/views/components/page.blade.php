@props(['title', 'eyebrow' => 'Information'])
<x-layouts.store :title="$title">
    {{-- Header Banner --}}
    <div class="bg-gradient-to-br from-[#1F070E] via-[#2B0912] to-[#120408] text-white py-10 sm:py-14">
        <div class="container-x">
            <div class="mx-auto max-w-4xl">
                <nav class="text-xs text-white/60 mb-3" aria-label="Breadcrumb">
                    <ol class="flex items-center gap-2">
                        <li><a href="{{ route('home') }}" class="hover:text-white transition">Home</a></li>
                        <li aria-hidden="true">/</li>
                        <li class="text-white font-medium">{{ $eyebrow }}</li>
                    </ol>
                </nav>
                <span class="eyebrow text-[#ff8fa3]">{{ $eyebrow }}</span>
                <h1 class="display mt-2 text-3xl sm:text-5xl text-white">{{ $title }}</h1>
                <p class="mt-2 text-xs sm:text-sm text-white/70">Official Policy &amp; Terms · Last updated {{ now()->format('F Y') }}</p>
            </div>
        </div>
    </div>

    {{-- Content Body --}}
    <div class="container-x py-8 sm:py-12">
        <div class="mx-auto max-w-4xl">
            <div class="card p-6 sm:p-10 shadow-sm border border-line bg-white">
                <div class="prose-store text-body leading-relaxed max-w-none">
                    {{ $slot }}
                </div>

                {{-- Support Note --}}
                <div class="mt-10 border-t border-line pt-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <p class="text-xs text-muted">
                        Questions about this policy? Our support team is available 24/7.
                    </p>
                    @if ($wa = admin_whatsapp_number())
                        <a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener" class="btn btn-outline py-2 px-4 text-xs font-bold shrink-0">
                            Ask Support on WhatsApp →
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-layouts.store>
