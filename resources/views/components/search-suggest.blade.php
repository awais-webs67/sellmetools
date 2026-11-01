{{-- Instant search suggestions. Place inside a `relative` element that has x-data="liveSearch". --}}
<div x-show="open && term.length >= 2" x-cloak
     x-transition:enter="transition ease-out duration-150"
     x-transition:enter-start="opacity-0 -translate-y-1"
     x-transition:enter-end="opacity-100 translate-y-0"
     class="absolute inset-x-0 top-full z-50 mt-2 overflow-hidden rounded-xl border border-line bg-white text-left shadow-[0_18px_40px_-12px_rgba(16,24,40,.28)]"
     role="listbox">

    {{-- Loading skeleton (first request only) --}}
    <div x-show="loading && !products.length" class="space-y-1 p-2" aria-hidden="true">
        @for ($i = 0; $i < 3; $i++)
            <div class="flex items-center gap-3 px-2 py-2">
                <span class="h-10 w-10 shrink-0 animate-pulse rounded-lg bg-grey"></span>
                <span class="flex-1 space-y-1.5">
                    <span class="block h-3 w-2/3 animate-pulse rounded bg-grey"></span>
                    <span class="block h-2.5 w-1/3 animate-pulse rounded bg-grey"></span>
                </span>
            </div>
        @endfor
    </div>

    {{-- Product matches --}}
    <div x-show="products.length" class="max-h-[60vh] overflow-y-auto overscroll-contain p-1.5">
        <template x-for="(p, i) in products" :key="p.url">
            <a :href="p.url" role="option" :aria-selected="active === i" @mouseenter="active = i"
               class="flex items-center gap-3 rounded-lg px-2 py-2 transition active:scale-[0.99]"
               :class="active === i ? 'bg-brand-tint' : 'hover:bg-grey-soft'">
                <span class="h-10 w-10 shrink-0 overflow-hidden rounded-lg bg-grey-soft">
                    <template x-if="p.image"><img :src="p.image" alt="" class="h-full w-full object-cover" loading="lazy" decoding="async"></template>
                    <template x-if="!p.image"><span class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand to-[#2B0912] text-sm font-bold text-white" x-text="p.name.charAt(0)"></span></template>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-ink" x-html="mark(p.name)"></span>
                    <span class="block truncate text-xs text-muted" x-text="p.category || 'Digital product'"></span>
                </span>
                <span class="shrink-0 text-right">
                    <span class="block text-sm font-bold text-ink" x-text="p.price"></span>
                    <span class="block text-[11px] font-semibold" :class="p.in_stock ? 'text-success' : 'text-danger'" x-text="p.in_stock ? 'In stock' : 'Sold out'"></span>
                </span>
            </a>
        </template>
    </div>

    {{-- Category matches --}}
    <div x-show="categories.length" class="flex flex-wrap items-center gap-1.5 border-t border-line px-3 py-2.5">
        <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">Categories</span>
        <template x-for="c in categories" :key="c.url">
            <a :href="c.url" class="rounded-full border border-line-strong px-2.5 py-1 text-xs font-semibold text-ink transition hover:border-brand hover:text-brand" x-text="c.name"></a>
        </template>
    </div>

    {{-- No results --}}
    <div x-show="searched && !loading && !products.length && !categories.length" class="px-4 py-5 text-center">
        <p class="text-sm font-semibold text-ink">No match for “<span x-text="term"></span>”</p>
        <p class="mt-1 text-xs text-muted">Try a shorter name like “ChatGPT” or “Canva”.</p>
    </div>

    {{-- See all --}}
    <button type="submit" x-show="products.length"
            class="flex w-full items-center justify-between border-t border-line bg-grey-soft px-4 py-2.5 text-xs font-semibold text-ink transition hover:bg-brand-tint hover:text-brand">
        <span>See all results for “<span x-text="term"></span>”</span>
        <span aria-hidden="true">→</span>
    </button>
</div>
