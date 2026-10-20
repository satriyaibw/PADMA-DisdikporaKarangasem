@php
    $organization = config('landing.organization');
    $socials = [
        ['label' => 'Facebook Disdikpora Karangasem', 'href' => $organization['same_as'][0], 'icon' => 'facebook'],
        ['label' => 'Instagram Disdikpora Karangasem', 'href' => $organization['same_as'][1], 'icon' => 'instagram'],
        ['label' => 'TikTok Disdikpora Karangasem', 'href' => $organization['same_as'][2], 'icon' => 'tiktok'],
        ['label' => 'YouTube Disdikpora Karangasem', 'href' => $organization['same_as'][3], 'icon' => 'youtube'],
    ];

    $dataLinks = [
        ['label' => 'Dashboard Publik', 'href' => route('public.dashboard'), 'external' => false],
        ['label' => 'Login VIP', 'href' => route('login'), 'external' => false],
        ['label' => 'Dapodik', 'href' => 'https://dapodik.dikdasmen.go.id/', 'external' => true],
        ['label' => 'Rapor Pendidikan', 'href' => 'https://raporpendidikan.kemendikdasmen.go.id/', 'external' => true],
        ['label' => 'PPID Kabupaten Karangasem', 'href' => 'https://ppid.karangasemkab.go.id/', 'external' => true],
        ['label' => 'Situs Disdikpora', 'href' => $organization['url'], 'external' => true],
    ];
@endphp

<footer class="bg-ink-950 text-brand-100">
    <div class="container-page py-16 sm:py-20">
        <div class="grid gap-10 md:grid-cols-2 lg:grid-cols-12 lg:gap-8">
            <div class="lg:col-span-3">
                <div class="inline-flex items-center gap-3 rounded-2xl bg-white px-3 py-2.5">
                    <img
                        src="{{ asset('images/branding/logo-disdikpora.webp') }}"
                        width="441"
                        height="114"
                        alt="Logo Disdikpora Kabupaten Karangasem"
                        class="h-8 w-auto"
                    >
                    <span class="h-8 w-px bg-brand-100" aria-hidden="true"></span>
                    <img
                        src="{{ asset('images/branding/padma-mark-256.webp') }}"
                        width="256"
                        height="256"
                        alt="Logo PADMA"
                        class="h-9 w-9 object-contain"
                    >
                </div>

                <p class="mt-5 text-sm font-semibold tracking-wide text-white">PADMA Kabupaten Karangasem</p>
                <p class="mt-2 max-w-sm text-sm leading-relaxed text-brand-200">
                    Portal Analisis Dapodik menuju Karangasem Agung. Kanal visualisasi data pendidikan yang terbuka, terpadu, dan mudah dipahami.
                </p>

                <div class="mt-5 flex items-center gap-2">
                    @foreach ($socials as $social)
                        <a
                            href="{{ $social['href'] }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="{{ $social['label'] }}"
                            class="flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 text-brand-100 transition hover:border-gold-300/50 hover:bg-white/5 hover:text-gold-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-300"
                        >
                            <x-ui.icon :name="$social['icon']" class="h-5 w-5" />
                        </a>
                    @endforeach
                </div>
            </div>

            <div class="lg:col-span-2">
                <h2 class="text-xs font-semibold uppercase tracking-[0.18em] text-gold-300">Jelajahi</h2>
                <ul class="mt-4 space-y-2.5 text-sm">
                    @foreach ([['Beranda', '#main'], ['Tentang', '#tentang'], ['Data', '#data'], ['Kecamatan', '#kecamatan'], ['FAQ', '#faq']] as [$label, $href])
                        <li>
                            <a href="{{ $href }}" class="rounded text-brand-200 transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-300">
                                {{ $label }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="lg:col-span-3">
                <h2 class="text-xs font-semibold uppercase tracking-[0.18em] text-gold-300">Data & Layanan</h2>
                <ul class="mt-4 space-y-2.5 text-sm">
                    @foreach ($dataLinks as $link)
                        <li>
                            <a
                                href="{{ $link['href'] }}"
                                @if ($link['external']) target="_blank" rel="noopener noreferrer" @endif
                                class="inline-flex items-center gap-1.5 rounded text-brand-200 transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-300"
                            >
                                {{ $link['label'] }}
                                @if ($link['external'])
                                    <x-ui.icon name="external-link" class="h-3.5 w-3.5 opacity-60" />
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="lg:col-span-4">
                <h2 class="text-xs font-semibold uppercase tracking-[0.18em] text-gold-300">Kontak</h2>
                <address class="mt-4 space-y-3 text-sm not-italic leading-relaxed text-brand-200">
                    <p class="flex gap-2.5">
                        <x-ui.icon name="map-pin" class="mt-0.5 h-4 w-4 shrink-0 text-gold-300" />
                        <span>{{ $organization['address']['display'] }}</span>
                    </p>
                    <p class="flex gap-2.5">
                        <x-ui.icon name="phone" class="mt-0.5 h-4 w-4 shrink-0 text-gold-300" />
                        <a href="tel:+623632787724" class="rounded transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-300">
                            {{ $organization['telephone_display'] }}
                        </a>
                    </p>
                    <p class="flex gap-2.5">
                        <x-ui.icon name="mail" class="mt-0.5 h-4 w-4 shrink-0 text-gold-300" />
                        <a href="mailto:{{ $organization['email'] }}" class="break-all rounded transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-300">
                            {{ $organization['email'] }}
                        </a>
                    </p>
                    <p class="flex gap-2.5">
                        <x-ui.icon name="globe" class="mt-0.5 h-4 w-4 shrink-0 text-gold-300" />
                        <a href="{{ $organization['url'] }}" target="_blank" rel="noopener noreferrer" class="break-all rounded transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-300">
                            pendidikan.karangasemkab.go.id
                        </a>
                    </p>
                </address>
            </div>
        </div>

        <div class="mt-12 flex flex-col gap-4 border-t border-white/10 pt-6 text-xs text-brand-300 sm:flex-row sm:items-center sm:justify-between">
            <p>&copy; {{ date('Y') }} Dinas Pendidikan Kepemudaan dan Olahraga Kabupaten Karangasem.</p>
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                <a href="https://ppid.karangasemkab.go.id/" target="_blank" rel="noopener noreferrer" class="rounded transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-300">
                    Kebijakan Privasi (PPID)
                </a>
                <a href="#main" class="inline-flex items-center gap-1.5 rounded transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-300">
                    Kembali ke atas
                    <x-ui.icon name="arrow-up" class="h-3.5 w-3.5" />
                </a>
            </div>
        </div>
    </div>
</footer>
