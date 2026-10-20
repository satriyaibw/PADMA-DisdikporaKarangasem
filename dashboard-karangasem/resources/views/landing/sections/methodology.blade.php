<section id="metodologi" class="section-pad scroll-mt-24 border-b border-brand-100/60 bg-white">
    <div class="container-page">
        <div class="grid gap-10 lg:grid-cols-12 lg:gap-16">
            <div class="lg:col-span-5">
                <x-ui.section-heading
                    eyebrow="Metodologi & privasi"
                    title="Terbuka untuk dipahami, aman untuk digunakan."
                    description="PADMA menampilkan agregat, bukan data pribadi. Setiap angka memiliki sumber dan periode yang dapat ditelusuri."
                />

                <div class="mt-7 flex flex-wrap gap-2">
                    @foreach (['Tanpa nama & NIK', 'Agregat per wilayah', 'Sumber Dapodik', 'Akses rinci terbatas'] as $badge)
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-100 bg-brand-50 px-3 py-1.5 text-[11px] font-semibold text-brand-800">
                            <x-ui.icon name="check" class="h-3.5 w-3.5 text-brand-600" />
                            {{ $badge }}
                        </span>
                    @endforeach
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:col-span-7">
                <article class="rounded-2xl border border-brand-100 bg-white p-6 shadow-sm">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
                        <x-ui.icon name="lock" class="h-5 w-5" />
                    </span>
                    <h3 class="mt-5 text-base font-semibold text-ink-950">Data agregat, bukan data pribadi</h3>
                    <p class="mt-2.5 text-sm leading-relaxed text-slate-600">
                        Dashboard publik tidak memuat nama, NISN, NIK, atau informasi pribadi lainnya. Data rinci hanya dapat diakses pengguna berwenang sesuai kebijakan Disdikpora.
                    </p>
                    <a href="https://ppid.karangasemkab.go.id/" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-1.5 rounded text-sm font-semibold text-brand-700 hover:text-brand-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-700">
                        Kebijakan PPID
                        <x-ui.icon name="external-link" class="h-3.5 w-3.5" />
                    </a>
                </article>

                <article class="rounded-2xl border border-brand-100 bg-white p-6 shadow-sm">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
                        <x-ui.icon name="database" class="h-5 w-5" />
                    </span>
                    <h3 class="mt-5 text-base font-semibold text-ink-950">Sumber & pemutakhiran</h3>
                    <p class="mt-2.5 text-sm leading-relaxed text-slate-600">
                        Angka pada halaman ini merupakan agregat dari basis data Dapodik pada periode tertentu. Data mengikuti pemutakhiran yang tersedia dan tidak diperbarui secara real-time.
                    </p>

                    <dl class="mt-4 space-y-2 border-t border-brand-50 pt-4 text-xs">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Periode data</dt>
                            <dd class="font-semibold text-slate-700">{{ $period }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Terakhir diperbarui</dt>
                            <dd class="font-semibold text-slate-700">{{ $updatedAtDisplay }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Sumber</dt>
                            <dd class="font-semibold text-slate-700">Dapodik</dd>
                        </div>
                    </dl>
                </article>
            </div>
        </div>
    </div>
</section>
