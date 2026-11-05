@props(['align' => 'left'])
{{-- Instant search suggestions. Place inside a `relative` element that has x-data="liveSearch" or "liveFilter". --}}
<div x-show="open && term.length >= 1" x-cloak
     x-transition:enter="transition ease-out duration-150"
     x-transition:enter-start="opacity-0 -translate-y-1.5 scale-[0.99]"
     x-transition:enter-end="opacity-100 translate-y-0 scale-100"
     x-transition:leave="transition ease-in duration-100"
     x-transition:leave-start="opacity-100 translate-y-0"
     x-transition:leave-end="opacity-0 -translate-y-1"
     @class([
         'absolute top-full z-50 mt-2 w-full sm:w-[430px] md:w-[470px] max-w-[calc(100vw-1.5rem)] overflow-hidden rounded-2xl border border-line-strong/80 bg-white text-left shadow-[0_22px_48px_-12px_rgba(15,23,42,0.28)] ring-1 ring-black/5',
         'left-0' => $align !== 'right',
         'left-0 sm:left-auto sm:right-0' => $align === 'right',
     ])
     role="listbox">

    {{-- Dropdown Header Bar --}}
    <div class="flex items-center justify-between border-b border-line bg-grey-soft/80 px-3.5 py-2 text-[11px] font-bold uppercase tracking-wider text-muted">
        <span class="inline-flex items-center gap-1.5">
            <svg class="h-3.5 w-3.5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <span x-text="products.length ? ('Products (' + products.length + ')') : 'Instant Search'"></span>
        </span>
        <span x-show="loading" class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-brand normal-case">
            <span class="h-3 w-3 animate-spin rounded-full border-2 border-line border-t-brand"></span>
            <span>Searching...</span>
        </span>
        <button type="button" x-show="!loading" @click="open = false" class="rounded px-1.5 py-0.5 text-[10px] font-semibold text-muted hover:bg-grey hover:text-ink transition cursor-pointer">
            ESC
        </button>
    </div>

    {{-- Loading skeleton (first request only) --}}
    <div x-show="loading && !products.length" class="space-y-1.5 p-2.5" aria-hidden="true">
        @for ($i = 0; $i < 3; $i++)
            <div class="flex items-center gap-3 rounded-xl px-2.5 py-2">
                <span class="h-11 w-11 shrink-0 animate-pulse rounded-xl bg-grey"></span>
                <span class="flex-1 space-y-2">
                    <span class="block h-3.5 w-3/4 animate-pulse rounded bg-grey"></span>
                    <span class="block h-2.5 w-2/5 animate-pulse rounded bg-grey"></span>
                </span>
                <span class="h-6 w-16 shrink-0 animate-pulse rounded-lg bg-grey"></span>
            </div>
        @endfor
    </div>

    {{-- Product matches --}}
    <div x-show="products.length" class="max-h-[62vh] sm:max-h-[380px] overflow-y-auto overscroll-contain divide-y divide-line/60 p-1.5">
        <template x-for="(p, i) in products" :key="p.url">
            <a :href="p.url" role="option" :aria-selected="active === i" @mouseenter="active = i"
               class="group flex items-center gap-3 rounded-xl px-2.5 py-2.5 transition duration-150 active:scale-[0.99]"
               :class="active === i ? 'bg-brand-tint' : 'hover:bg-grey-soft'">

                {{-- Product Thumbnail --}}
                <span class="relative h-11 w-11 shrink-0 overflow-hidden rounded-xl border border-line bg-grey-soft shadow-2xs">
                    <template x-if="p.image">
                        <img :src="p.image" :alt="p.name" loading="lazy" class="h-full w-full object-cover transition duration-200 group-hover:scale-105">
                    </template>
                    <template x-if="!p.image">
                        <span class="flex h-full w-full items-center justify-center bg-ink text-sm font-extrabold text-white" x-text="p.name ? p.name.charAt(0).toUpperCase() : 'S'"></span>
                    </template>
                </span>

                {{-- Product Name + Category + Stock Status --}}
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[13px] sm:text-sm font-bold text-ink group-hover:text-brand transition" x-html="mark(p.name)"></span>
                    <span class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px]">
                        <span class="truncate font-medium text-muted" x-text="p.category || 'Digital Tool'"></span>
                        <span class="text-line-strong">•</span>
                        <span class="inline-flex items-center gap-1 font-semibold"
                              :class="($store.stock && p.id && $store.stock.get(p.id) ? $store.stock.get(p.id).in_stock : p.in_stock) ? 'text-success' : 'text-danger'">
                            <span class="h-1.5 w-1.5 rounded-full" :class="($store.stock && p.id && $store.stock.get(p.id) ? $store.stock.get(p.id).in_stock : p.in_stock) ? 'bg-success' : 'bg-danger'"></span>
                            <span x-text="($store.stock && p.id && $store.stock.get(p.id) ? $store.stock.get(p.id).in_stock : p.in_stock) ? (($store.stock && p.id && $store.stock.get(p.id) ? $store.stock.get(p.id).unlimited : p.unlimited) ? 'In Stock' : 'In Stock (' + (($store.stock && p.id && $store.stock.get(p.id) ? $store.stock.get(p.id).qty : p.qty) || 1) + ')') : 'Sold Out (0)'"></span>
                        </span>
                    </span>
                </span>

                {{-- Price Badge + Arrow --}}
                <span class="shrink-0 flex items-center gap-2 text-right">
                    <span class="inline-flex flex-col items-end">
                        <span class="rounded-lg px-2 py-1 text-xs sm:text-[13px] font-extrabold tracking-tight"
                              :class="p.is_free ? 'bg-success-tint text-success' : 'bg-grey-soft text-ink group-hover:bg-white border border-line'"
                              x-text="p.is_free ? 'FREE' : (p.price_raw > 0 ? $store.currency.format(p.price_raw, true) : p.price)"></span>
                    </span>
                    <svg class="hidden sm:block h-4 w-4 text-muted opacity-0 -translate-x-1 transition group-hover:opacity-100 group-hover:translate-x-0 group-hover:text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </span>
            </a>
        </template>
    </div>

    {{-- Category matches --}}
    <div x-show="categories.length" class="flex flex-wrap items-center gap-1.5 border-t border-line bg-grey-soft/50 px-3.5 py-2.5">
        <span class="text-[10px] font-bold uppercase tracking-wider text-muted mr-1">Categories:</span>
        <template x-for="c in categories" :key="c.url">
            <a :href="c.url" class="inline-flex items-center gap-1 rounded-full border border-line-strong bg-white px-2.5 py-1 text-xs font-semibold text-ink shadow-2xs transition hover:border-brand hover:text-brand" x-text="c.name"></a>
        </template>
    </div>

    {{-- No results --}}
    <div x-show="searched && !loading && !products.length && !categories.length" class="px-4 py-7 text-center">
        <div class="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-grey-soft text-muted">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        </div>
        <p class="text-sm font-bold text-ink">No match for “<span x-text="term"></span>”</p>
        <p class="mt-0.5 text-xs text-muted">Try a shorter keyword (e.g. ChatGPT, Canva, CapCut) or browse all categories.</p>
    </div>

    {{-- See all --}}
    <button type="submit" x-show="products.length"
            class="flex w-full items-center justify-between border-t border-line bg-grey-soft px-4 py-2.5 text-xs font-bold text-ink transition hover:bg-brand hover:text-white cursor-pointer">
        <span class="truncate">View all results for “<span x-text="term"></span>”</span>
        <span class="ml-2 shrink-0 inline-flex items-center gap-1 rounded border border-current/20 px-1.5 py-0.5 text-[10px] font-semibold">
            Enter ↵
        </span>
    </button>
</div>

