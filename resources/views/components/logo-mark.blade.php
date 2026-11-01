@php $customLogo = setting('store_logo'); @endphp
@if ($customLogo)
    <img src="{{ \Illuminate\Support\Str::startsWith($customLogo, ['http://', 'https://']) ? $customLogo : url('/media/'.ltrim($customLogo, '/')) }}"
         alt="{{ setting('store_name') }}"
         {{ $attributes->merge(['class' => 'rounded-lg object-contain']) }} />
@else
    <img src="{{ asset('images/logo-icon.png') }}"
         alt="{{ setting('store_name') }}"
         {{ $attributes->merge(['class' => 'object-contain']) }} />
@endif
