@php $customLogo = setting('store_logo'); @endphp
@if ($customLogo)
    <img src="{{ \Illuminate\Support\Str::startsWith($customLogo, ['http://', 'https://']) ? $customLogo : url('/media/'.ltrim($customLogo, '/')) }}"
         alt="{{ setting('store_name') }}"
         {{ $attributes->merge(['class' => 'rounded-lg object-contain']) }} />
@else
    <svg {{ $attributes }} viewBox="0 0 40 40" fill="none" aria-hidden="true">
        <defs>
            <linearGradient id="smtGrad" x1="0" y1="0" x2="40" y2="40" gradientUnits="userSpaceOnUse">
                <stop stop-color="#C81E3A"/>
                <stop offset="1" stop-color="#6B0F22"/>
            </linearGradient>
        </defs>
        <rect width="40" height="40" rx="10" fill="url(#smtGrad)"/>
        <rect x="1.5" y="1.5" width="37" height="37" rx="8.5" stroke="white" stroke-opacity="0.18"/>
        <path d="M22.2 8L11.5 21.6H19.4L17.8 32L28.5 18.4H20.6L22.2 8Z" fill="white"/>
    </svg>
@endif
