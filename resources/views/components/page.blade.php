@props(['title', 'eyebrow' => 'Information'])
<x-layouts.store :title="$title">
    <div class="container-x py-12 lg:py-16">
        <div class="mx-auto max-w-3xl">
            <span class="eyebrow">{{ $eyebrow }}</span>
            <h1 class="display mt-2 text-5xl">{{ $title }}</h1>
            <p class="mt-2 text-sm text-muted">Last updated {{ now()->format('F Y') }}</p>
            <div class="prose-store mt-8">{{ $slot }}</div>
        </div>
    </div>
</x-layouts.store>

