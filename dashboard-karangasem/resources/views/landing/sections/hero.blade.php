<section class="relative overflow-hidden border-b border-brand-100/60 bg-gradient-to-b from-brand-50 via-white to-white pb-20 pt-12 sm:pt-16 lg:pb-24 lg:pt-20">
    <div class="landing-grid pointer-events-none absolute inset-0 opacity-70" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -right-24 top-12 h-80 w-80 rounded-full bg-gold-200/20 blur-3xl" aria-hidden="true"></div>

    <div class="container-page relative">
        <div class="grid items-center gap-12 lg:grid-cols-12 lg:gap-10">
            <div class="lg:col-span-7">
                <p class="inline-flex items-center gap-2 rounded-full border border-brand-100 bg-white px-4 py-1.5 text-xs font-semibold text-brand-800 shadow-sm">
                    <x-ui.icon name="shield-check" class="h-4 w-4 text-gold-600" />
                    Portal resmi Disdikpora Kabupaten Karangasem
                </p>

                <h1 class="mt-6 max-w-3xl text-4xl font-semibold leading-[1.06] tracking-tight text-ink-950 sm:text-5xl lg:text-6xl">
                    Data pendidikan Karangasem,
                    <span class="mt-2 block bg-gradient-to-r from-brand-800 via-brand-600 to-gold-600 bg-clip-text text-transparent">
                        terbuka untuk kemajuan bersama.
                    </span>
                </h1>

                <p class="mt-6 max-w-2xl text-base leading-relaxed text-slate-600 sm:text-lg">
                    PADMA menghadirkan data satuan pendidikan, peserta didik, rombongan belajar, dan pendidik dalam satu dashboard yang mudah dipahami — untuk sekolah, pemerintah, dan masyarakat.
                </p>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <x-ui.button :href="route('public.dashboard')" size="lg">
                        Jelajahi Dashboard Publik
                        <x-ui.icon name="arrow-right" class="h-4 w-4" />
                    </x-ui.button>
                    <x-ui.button href="#tentang" variant="secondary" size="lg">
                        Kenali PADMA
                    </x-ui.button>
                </div>

                <p class="mt-6 flex items-start gap-2 text-sm leading-relaxed text-slate-500">
                    <x-ui.icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                    <span>Dikelola oleh Dinas Pendidikan Kepemudaan dan Olahraga Kabupaten Karangasem.</span>
                </p>

                <dl class="mt-8 grid max-w-xl grid-cols-3 gap-4 border-t border-brand-100 pt-6">
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Kecamatan</dt>
                        <dd class="mt-1 text-2xl font-semibold text-brand-900">8</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Satuan pendidikan</dt>
                        <dd class="mt-1 text-2xl font-semibold text-brand-900">{{ $stats[0]['display'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Peserta didik</dt>
                        <dd class="mt-1 text-2xl font-semibold text-brand-900">{{ $stats[1]['display'] }}</dd>
                    </div>
                </dl>

                @if (($snapshotStatus ?? 'unverified') !== 'verified')
                    <p class="mt-3 text-xs leading-relaxed text-amber-700">
                        Angka sementara; snapshot statistik belum diverifikasi.
                    </p>
                @endif
            </div>

            <div class="lg:col-span-5">
                <div class="relative mx-auto max-w-md lg:max-w-none">
                    <div class="overflow-hidden rounded-[2rem] border border-white/10 bg-brand-950 shadow-2xl shadow-brand-900/20">
                        <img
                            src="{{ asset('images/landing/hero-agung.svg') }}"
                            width="720"
                            height="640"
                            alt=""
                            class="h-auto w-full"
                            loading="eager"
                            fetchpriority="high"
                            decoding="async"
                        >
                    </div>

                    <div class="mt-4 rounded-2xl border border-brand-100 bg-white p-4 shadow-xl shadow-brand-900/10 sm:p-5 lg:absolute lg:-bottom-8 lg:left-6 lg:right-6 lg:mt-0 lg:border-white/60 lg:bg-white/95 lg:backdrop-blur">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-700">Ringkasan data</p>
                            <span class="rounded-full bg-brand-50 px-2.5 py-1 text-[10px] font-semibold text-brand-700">{{ $period }}</span>
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-3 border-t border-brand-50 pt-3">
                            @foreach ($stats as $stat)
                                <div class="min-w-0">
                                    <dt class="truncate text-[10px] font-medium leading-tight text-slate-500">{{ $stat['label'] }}</dt>
                                    <dd class="mt-0.5 text-lg font-semibold tracking-tight text-ink-950">
                                        <data value="{{ $stat['value'] }}">{{ $stat['display'] }}</data>
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
