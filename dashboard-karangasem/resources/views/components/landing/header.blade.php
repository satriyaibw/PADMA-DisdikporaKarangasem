@php
    $links = [
        ['label' => 'Tentang', 'href' => '#tentang'],
        ['label' => 'Data', 'href' => '#data'],
        ['label' => 'Kecamatan', 'href' => '#kecamatan'],
        ['label' => 'FAQ', 'href' => '#faq'],
    ];
@endphp

<header class="sticky top-0 z-40 border-b border-brand-100/80 bg-white/90 backdrop-blur-xl">
    <div class="container-page flex h-20 items-center justify-between gap-3">
        <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-2.5 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-700 sm:gap-3.5" aria-label="PADMA Karangasem — Beranda">
            <img
                src="{{ asset('images/branding/logo-disdikpora.webp') }}"
                width="441"
                height="114"
                alt="Logo Disdikpora Kabupaten Karangasem"
                class="h-8 w-auto shrink-0 sm:h-9"
            >
            <span class="h-8 w-px shrink-0 bg-brand-100" aria-hidden="true"></span>
            <img
                src="{{ asset('images/branding/padma-mark-256.webp') }}"
                width="256"
                height="256"
                alt="Logo PADMA"
                class="h-10 w-10 shrink-0 object-contain sm:h-11 sm:w-11"
            >
            <span class="hidden min-w-0 flex-col sm:flex">
                <span class="text-lg font-semibold tracking-tight text-brand-900">PADMA</span>
                <span class="truncate text-[11px] font-medium text-slate-500">Disdikpora Karangasem</span>
            </span>
        </a>

        <nav class="hidden items-center gap-7 lg:flex" aria-label="Navigasi utama">
            @foreach ($links as $link)
                <a href="{{ $link['href'] }}" class="rounded-lg py-2 text-sm font-medium text-slate-600 transition hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-700">
                    {{ $link['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="hidden items-center gap-2 lg:flex">
            <x-ui.button :href="route('login')" variant="ghost" size="sm">Login VIP</x-ui.button>
            <x-ui.button :href="route('public.dashboard')" size="sm">
                Dashboard Publik
                <x-ui.icon name="arrow-right" class="h-4 w-4" />
            </x-ui.button>
        </div>

        <details class="group relative lg:hidden" data-mobile-menu>
            <summary class="flex h-11 w-11 cursor-pointer list-none items-center justify-center rounded-xl border border-brand-100 bg-white text-brand-800 shadow-sm transition hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700 [&::-webkit-details-marker]:hidden">
                <span class="sr-only">Buka menu navigasi</span>
                <x-ui.icon name="menu" class="h-6 w-6 group-open:hidden" />
                <x-ui.icon name="x" class="hidden h-6 w-6 group-open:block" />
            </summary>

            <div class="absolute right-0 top-full z-50 mt-3 w-[min(20rem,calc(100vw-2rem))] rounded-2xl border border-brand-100 bg-white p-3 shadow-xl">
                <nav class="flex flex-col" aria-label="Navigasi seluler">
                    <a href="{{ route('home') }}" class="rounded-xl px-4 py-3 text-sm font-medium text-slate-700 hover:bg-brand-50 hover:text-brand-800">Beranda</a>
                    @foreach ($links as $link)
                        <a href="{{ $link['href'] }}" class="rounded-xl px-4 py-3 text-sm font-medium text-slate-700 hover:bg-brand-50 hover:text-brand-800">
                            {{ $link['label'] }}
                        </a>
                    @endforeach
                </nav>

                <div class="mt-3 flex flex-col gap-2 border-t border-brand-100 pt-3">
                    <x-ui.button :href="route('public.dashboard')" size="sm" class="w-full">
                        Dashboard Publik
                        <x-ui.icon name="arrow-right" class="h-4 w-4" />
                    </x-ui.button>
                    <x-ui.button :href="route('login')" variant="secondary" size="sm" class="w-full">Login VIP</x-ui.button>
                </div>
            </div>
        </details>
    </div>
</header>
