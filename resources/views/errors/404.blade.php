<x-layouts.store
    title="Page Not Found (404)"
    description="The page or product you are looking for could not be found. Browse our latest AI tools, SEO tools, and digital subscriptions."
    robots="noindex, follow"
>
    <section class="container-x py-16 sm:py-24 text-center">
        <div class="mx-auto max-w-xl rounded-2xl border border-line bg-white p-8 sm:p-10 shadow-sm">
            <span class="inline-flex items-center rounded-full bg-brand-tint px-3 py-1 text-xs font-bold uppercase tracking-wider text-brand">
                404 · Page Not Found
            </span>
            <h1 class="display mt-4 text-3xl sm:text-5xl text-ink">
                This Page Has Moved or Does Not Exist
            </h1>
            <p class="mt-3 text-sm sm:text-base leading-relaxed text-body">
                No worries — explore our verified AI tools, cheapest SEO tools, design suites, VPNs, and digital subscriptions in Pakistan with instant delivery.
            </p>
            <div class="mt-7 flex flex-wrap items-center justify-center gap-3">
                <a href="{{ route('shop') }}" class="btn btn-primary px-6 py-3 text-sm font-bold">
                    Browse All Tools →
                </a>
                <a href="{{ route('home') }}" class="btn btn-outline px-5 py-3 text-sm font-bold">
                    Back to Homepage
                </a>
            </div>
        </div>
    </section>
</x-layouts.store>
