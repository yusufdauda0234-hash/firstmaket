<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'FirstMarket') }}</title>

    {{-- Brand logo everywhere the page is referenced: tab icon, share cards --}}
    <link rel="icon" type="image/png" href="{{ asset('images/brand/logo-mark-blue.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/brand/logo-mark-blue.png') }}">
    <meta property="og:site_name" content="{{ config('app.name', 'FirstMarket') }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ config('app.name', 'FirstMarket') }} — Just Order. We Deliver">
    <meta property="og:image" content="{{ asset('images/brand/logo-primary.png') }}">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:image" content="{{ asset('images/brand/logo-primary.png') }}">

    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
