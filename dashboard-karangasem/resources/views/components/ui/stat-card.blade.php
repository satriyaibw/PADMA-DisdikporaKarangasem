@props(['stat', 'icon' => 'chart'])

<article {{ $attributes->merge(['class' => 'flex h-full flex-col gap-4 rounded-2xl border border-brand-100 bg-white p-5 shadow-sm sm:p-6']) }}>
    <div class="flex items-center justify-between gap-3">
        <p class="text-sm font-semibold text-slate-600">{{ $stat['label'] }}</p>
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
            <x-ui.icon :name="$icon" class="h-5 w-5" />
        </span>
    </div>

    <div class="mt-auto">
        <data value="{{ $stat['value'] }}" class="block text-4xl font-semibold tracking-tight text-ink-950 sm:text-[2.6rem]">
            {{ $stat['display'] }}
        </data>
        <p class="mt-1 text-xs leading-relaxed text-slate-500">{{ $stat['note'] }}</p>
    </div>
</article>
