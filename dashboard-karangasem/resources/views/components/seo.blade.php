@props(['meta', 'schema'])

<title>{{ $meta['title'] }}</title>
<meta name="description" content="{{ $meta['description'] }}">
<meta name="robots" content="{{ $meta['robots'] }}">
<meta name="googlebot" content="{{ $meta['robots'] }}">
<meta name="author" content="Dinas Pendidikan Kepemudaan dan Olahraga Kabupaten Karangasem">
<meta name="application-name" content="PADMA Karangasem">
<meta name="theme-color" content="#082A4F">
<meta name="color-scheme" content="light">
<meta name="geo.region" content="ID-BA">
<meta name="geo.placename" content="Karangasem">
<link rel="canonical" href="{{ $meta['canonical'] }}">

{{-- Open Graph --}}
<meta property="og:type" content="{{ $meta['type'] }}">
<meta property="og:site_name" content="PADMA Karangasem">
<meta property="og:locale" content="{{ $meta['locale'] }}">
<meta property="og:title" content="{{ $meta['title'] }}">
<meta property="og:description" content="{{ $meta['description'] }}">
<meta property="og:url" content="{{ $meta['canonical'] }}">
<meta property="og:image" content="{{ $meta['image'] }}">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:image:alt" content="{{ $meta['image_alt'] }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">

{{-- Twitter/X --}}
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $meta['title'] }}">
<meta name="twitter:description" content="{{ $meta['description'] }}">
<meta name="twitter:image" content="{{ $meta['image'] }}">
<meta name="twitter:image:alt" content="{{ $meta['image_alt'] }}">

<link rel="alternate" hreflang="id-ID" href="{{ $meta['canonical'] }}">
<link rel="alternate" hreflang="x-default" href="{{ $meta['canonical'] }}">
<link rel="manifest" href="{{ asset('site.webmanifest') }}">
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon-180.png') }}">

@if (config('seo.google_site_verification'))
    <meta name="google-site-verification" content="{{ config('seo.google_site_verification') }}">
@endif

@if (config('seo.bing_site_verification'))
    <meta name="msvalidate.01" content="{{ config('seo.bing_site_verification') }}">
@endif

<script type="application/ld+json">@json($schema)</script>
