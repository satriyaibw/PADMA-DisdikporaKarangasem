@props([
    'eyebrow' => null,
    'title',
    'description' => null,
    'align' => 'left',
    'light' => false,
])

@php
    $alignment = $align === 'center'
        ? 'items-center text-center mx-auto'
        : 'items-start text-left';
@endphp

<div {{ $attributes->merge(['class' => 'flex max-w-3xl flex-col gap-4 '.$alignment]) }}>
    @if ($eyebrow)
        <p class="text-xs font-semibold uppercase tracking-[0.2em] {{ $light ? 'text-gold-300' : 'text-gold-700' }}">
            {{ $eyebrow }}
        </p>
    @endif

    <h2 class="text-3xl font-semibold tracking-tight sm:text-4xl {{ $light ? 'text-white' : 'text-ink-950' }}">
        {{ $title }}
    </h2>

    @if ($description)
        <p class="max-w-2xl text-base leading-relaxed sm:text-lg {{ $light ? 'text-brand-100' : 'text-slate-600' }}">
            {{ $description }}
        </p>
    @endif
</div>
