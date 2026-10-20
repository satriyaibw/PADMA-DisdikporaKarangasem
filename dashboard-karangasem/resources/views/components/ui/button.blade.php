@props([
    'href' => null,
    'variant' => 'primary',
    'size' => 'md',
])

@php
    $variants = [
        'primary' => 'bg-brand-700 text-white shadow-sm hover:bg-brand-800',
        'gold' => 'bg-gold-300 text-brand-950 shadow-sm hover:bg-gold-200',
        'secondary' => 'border border-brand-200 bg-white text-brand-800 shadow-sm hover:border-brand-300 hover:bg-brand-50',
        'ghost' => 'text-brand-700 hover:bg-brand-50',
        'white' => 'bg-white text-brand-800 shadow-sm hover:bg-brand-50',
        'outline-light' => 'border border-white/30 text-white hover:bg-white/10',
    ];

    $sizes = [
        'sm' => 'min-h-11 px-4 py-2.5 text-sm',
        'md' => 'min-h-11 px-5 py-3 text-sm',
        'lg' => 'min-h-12 px-6 py-3.5 text-base',
    ];

    $classes = 'inline-flex items-center justify-center gap-2 rounded-xl font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700';
    $classes .= ' '.($variants[$variant] ?? $variants['primary']);
    $classes .= ' '.($sizes[$size] ?? $sizes['md']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['class' => $classes, 'type' => 'button']) }}>{{ $slot }}</button>
@endif
