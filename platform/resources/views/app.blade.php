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
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
    <link rel="icon" type="image/svg+xml" href="/icons/icon.svg">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="mask-icon" href="/icons/icon.svg" color="#CE1126">

    @routes
    @vite('resources/js/app.js')
    @inertiaHead
</head>
<body class="bg-offwhite text-obsidian-900 antialiased font-sans">
    @inertia
</body>
</html>
