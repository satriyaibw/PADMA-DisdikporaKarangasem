<section id="tentang" class="section-pad scroll-mt-24 border-b border-brand-100/60 bg-white">
    <div class="container-page">
        <div class="grid items-center gap-10 lg:grid-cols-12 lg:gap-16">
            <div class="lg:col-span-5">
                <div class="relative overflow-hidden rounded-3xl border border-brand-100 bg-gradient-to-br from-brand-50 via-white to-gold-50 p-7 sm:p-9">
                    <div class="pointer-events-none absolute -right-12 -top-12 h-44 w-44 rounded-full bg-brand-200/30 blur-2xl" aria-hidden="true"></div>
                    <div class="pointer-events-none absolute -bottom-16 -left-12 h-48 w-48 rounded-full bg-gold-200/30 blur-2xl" aria-hidden="true"></div>

                    <div class="relative flex flex-col items-start">
                        <div class="flex h-28 w-28 items-center justify-center rounded-2xl border border-white bg-white p-2 shadow-sm">
                            <img
                                src="{{ asset('images/branding/padma-mark-256.webp') }}"
                                width="256"
                                height="256"
                                alt="Logo PADMA"
                                class="h-full w-full object-contain"
                                loading="lazy"
                                decoding="async"
                            >
                        </div>

                        <p class="mt-6 text-xs font-semibold uppercase tracking-[0.2em] text-gold-700">Filosofi PADMA</p>
                        <blockquote class="mt-3 text-lg font-semibold leading-snug tracking-tight text-brand-900 sm:text-xl">
                            “Padma” — bunga teratai yang tumbuh dari air, melambangkan ilmu yang terus mekar dan menerangi.
                        </blockquote>
                        <p class="mt-4 text-sm leading-relaxed text-slate-600">
                            Semangat ini disatukan dengan visi Karangasem AGUNG: Aman, Gigih, Unggul, Nyaman, dan Gemah Ripah Loh Jinawi.
                        </p>

                        <dl class="mt-6 grid w-full grid-cols-2 gap-3 border-t border-brand-100 pt-5">
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Kecamatan</dt>
                                <dd class="mt-1 text-2xl font-semibold text-brand-900">8</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Satuan pendidikan</dt>
                                <dd class="mt-1 text-2xl font-semibold text-brand-900">{{ $stats[0]['display'] }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-7">
                <x-ui.section-heading
                    eyebrow="Tentang PADMA"
                    title="Satu pintu untuk memahami pendidikan Karangasem."
                    description="PADMA adalah Portal Analisis Dapodik menuju Karangasem Agung, kanal visualisasi data pendidikan Kabupaten Karangasem. PADMA merangkum data Dapodik menjadi informasi yang ringkas, konsisten, dan mudah ditelusuri."
                />

                <div class="mt-6 space-y-4 text-sm leading-relaxed text-slate-600 sm:text-base">
                    <p>
                        Portal ini ditujukan bagi sekolah, pemerintah, peneliti, dan masyarakat yang membutuhkan gambaran pendidikan di 8 kecamatan: Karangasem, Kubu, Abang, Manggis, Bebandem, Rendang, Selat, dan Sidemen.
                    </p>
                    <p>
                        Dengan satu standar data, setiap kebijakan dapat dirumuskan dari realitas yang sama — bukan dari potongan informasi yang tersebar di berbagai berkas.
                    </p>
                </div>

                <ul class="mt-6 grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        'Data agregat yang konsisten dan mudah dibaca',
                        'Visualisasi per kecamatan dan jenjang pendidikan',
                        'Dashboard publik tanpa login untuk siapa saja',
                        'Area VIP untuk data rinci bagi pengguna berwenang',
                    ] as $item)
                        <li class="flex items-start gap-2.5 rounded-xl border border-brand-100 bg-brand-50/50 p-3 text-sm text-slate-700">
                            <x-ui.icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                            <span>{{ $item }}</span>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-7">
                    <x-ui.button :href="$organization['url']" variant="secondary" target="_blank" rel="noopener noreferrer">
                        Kunjungi situs Disdikpora
                        <x-ui.icon name="external-link" class="h-4 w-4" />
                    </x-ui.button>
                </div>
            </div>
        </div>
    </div>
</section>
