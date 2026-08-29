<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ request()->attributes->get('dir', 'rtl') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#CE1126">
    <meta name="description" content="{{ __('منصة الخدمات المنزلية والتجارية عند الطلب في اليمن') }}">

    <title inertia>{{ config('app.name', 'Yemen Services') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">

    @routes
    @vite('resources/js/app.js')
    @inertiaHead
</head>
<body class="bg-offwhite text-obsidian-900 antialiased font-sans">
    @inertia
</body>
</html>
