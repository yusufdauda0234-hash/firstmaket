<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'FirstMaket') }}</title>

    {{-- Brand logo everywhere the page is referenced: tab icon, share cards.
         Sized per slot rather than pointing everything at one large PNG — a
         browser drawing a 16px tab icon should not be handed a 512px file. --}}
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/brand/favicon-16.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/brand/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="48x48" href="{{ asset('images/brand/favicon-48.png') }}">
    {{-- iOS composites a transparent icon onto black, so this one is the
         variant with a solid backdrop. --}}
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/brand/apple-touch-icon.png') }}">

    <meta property="og:site_name" content="{{ config('app.name', 'FirstMaket') }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ config('app.name', 'FirstMaket') }} — Just Order. We Deliver">
    {{-- 1200x630 is what Facebook, WhatsApp, X and LinkedIn all crop towards;
         a square image gets its top and bottom cut off in the preview. --}}
    <meta property="og:image" content="{{ asset('images/brand/og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ asset('images/brand/og-image.png') }}">

    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
