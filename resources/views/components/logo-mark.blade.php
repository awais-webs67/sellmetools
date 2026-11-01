@php $customLogo = setting('store_logo'); @endphp
@if ($customLogo)
    <img src="{{ \Illuminate\Support\Str::startsWith($customLogo, ['http://', 'https://']) ? $customLogo : url('/media/'.ltrim($customLogo, '/')) }}"
         alt="{{ setting('store_name') }}"
         {{ $attributes->merge(['class' => 'rounded-lg object-contain']) }} />
@else
    <svg {{ $attributes }} viewBox="0 0 64 64" fill="none" aria-hidden="true">
        <defs>
            <linearGradient id="smtBrandGradMark" x1="4" y1="4" x2="60" y2="60" gradientUnits="userSpaceOnUse">
                <stop offset="0%" stop-color="#E11D48"/>
                <stop offset="50%" stop-color="#9B1B30"/>
                <stop offset="100%" stop-color="#1A0308"/>
            </linearGradient>
            <linearGradient id="smtSheenMark" x1="8" y1="6" x2="48" y2="28" gradientUnits="userSpaceOnUse">
                <stop offset="0%" stop-color="#FFFFFF" stop-opacity="0.3"/>
                <stop offset="100%" stop-color="#FFFFFF" stop-opacity="0"/>
            </linearGradient>
        </defs>

        <!-- Clean Rounded Squircle Badge Base -->
        <rect x="3" y="3" width="58" height="58" rx="16" fill="url(#smtBrandGradMark)"/>
        <rect x="3" y="3" width="58" height="58" rx="16" stroke="#FFFFFF" stroke-opacity="0.18" stroke-width="1.5"/>

        <!-- Subtle Glass Sheen Top Corner -->
        <path d="M5 19C5 11.268 11.268 5 19 5H38C26 5 5 18 5 35V19Z" fill="url(#smtSheenMark)"/>

        <!-- Professional Tech Monogram "S" with Integrated Lightning Power Bolt -->
        <!-- Top Arc & Bolt Tail -->
        <path d="M43 17H25C20.5817 17 17 20.5817 17 25C17 29.4183 20.5817 33 25 33H39L30 45H43L47 39H35L41 31C43.5 28.5 43.5 24 41 20.5L43 17Z" fill="#FFFFFF"/>
        <!-- Bottom Hook Arc -->
        <path d="M21 47H39C43.4183 47 47 43.4183 47 39C47 34.5817 43.4183 31 39 31H25L34 19H21L17 25H29L23 33C20.5 35.5 20.5 40 23 43.5L21 47Z" fill="#FFFFFF" fill-opacity="0.95"/>
        <!-- Central Energy Spark Diamond -->
        <polygon points="32,27 36,32 32,37 28,32" fill="#E11D48"/>
    </svg>
@endif
