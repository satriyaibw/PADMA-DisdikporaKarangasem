<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo :meta="$meta" :schema="$schema" />
    <link rel="preload" as="image" href="{{ asset('images/landing/hero-agung.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/landing.js'])
</head>
<body class="bg-white font-sans text-slate-700 antialiased selection:bg-gold-200 selection:text-ink-950">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded-xl focus:bg-brand-800 focus:px-4 focus:py-3 focus:text-sm focus:font-semibold focus:text-white">
        Langsung ke konten utama
    </a>

    <x-landing.header />

    <main id="main">
        @yield('content')
    </main>

    <x-landing.footer />
</body>
</html>
