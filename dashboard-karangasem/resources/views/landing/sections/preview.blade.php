@php
    $maxStudents = max(array_column($districts, 'students'));
    $previewStats = [
        ['label' => 'Satuan pendidikan', 'value' => $stats[0]['display']],
        ['label' => 'Peserta didik', 'value' => $stats[1]['display']],
        ['label' => 'Rombel', 'value' => $stats[2]['display']],
        ['label' => 'PTK', 'value' => $stats[3]['display']],
    ];
@endphp

<section class="section-pad border-b border-brand-100/60 bg-sand-50">
    <div class="container-page">
        <x-ui.section-heading
            align="center"
            eyebrow="Pratinjau dashboard"
            title="Lihat gambaran datanya sebelum masuk."
            description="Dashboard publik menyajikan ringkasan yang sama dengan halaman ini, dengan tampilan yang lebih interaktif dan dapat ditelusuri."
        />

        <figure class="mx-auto mt-10 max-w-5xl">
            <div class="overflow-hidden rounded-3xl border border-brand-100 bg-white shadow-2xl shadow-brand-900/10">
                <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <span class="h-2.5 w-2.5 rounded-full bg-rose-300" aria-hidden="true"></span>
                    <span class="h-2.5 w-2.5 rounded-full bg-amber-300" aria-hidden="true"></span>
                    <span class="h-2.5 w-2.5 rounded-full bg-emerald-300" aria-hidden="true"></span>
                    <div class="mx-auto flex min-w-0 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1 text-[10px] text-slate-500">
                        <x-ui.icon name="lock" class="h-3 w-3 shrink-0 text-emerald-600" />
                        <span class="truncate">Dashboard Publik PADMA</span>
                    </div>
                </div>

                <div
                    class="bg-slate-50 p-3 sm:p-5 lg:p-6"
                    role="img"
                    aria-label="Ilustrasi dashboard publik PADMA yang menampilkan ringkasan satuan pendidikan, peserta didik, rombongan belajar, PTK, dan sebaran peserta didik per kecamatan."
                >
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
                            <div class="flex items-center gap-2">
                                <img
                                    src="{{ asset('images/branding/padma-mark-256.webp') }}"
                                    width="256"
                                    height="256"
                                    alt=""
                                    class="h-7 w-7 object-contain"
                                    loading="lazy"
                                    decoding="async"
                                >
                                <div>
                                    <p class="text-xs font-semibold text-slate-800">PADMA Karangasem</p>
                                    <p class="text-[9px] text-slate-500">Dashboard Publik</p>
                                </div>
                            </div>
                            <span class="rounded-full bg-emerald-50 px-2 py-1 text-[9px] font-semibold text-emerald-700">Data agregat</span>
                        </div>

                        <div class="mt-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
                            @foreach ($previewStats as $previewStat)
                                <div class="rounded-xl border border-slate-100 bg-slate-50 p-3">
                                    <p class="text-[9px] font-medium uppercase tracking-wide text-slate-500">{{ $previewStat['label'] }}</p>
                                    <p class="mt-1 text-lg font-semibold tracking-tight text-brand-900">{{ $previewStat['value'] }}</p>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-3 grid gap-3 lg:grid-cols-3">
                            <div class="rounded-xl border border-slate-100 p-3 lg:col-span-2">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-[10px] font-semibold text-slate-700">Peserta didik per kecamatan</p>
                                    <span class="text-[9px] text-slate-400">8 wilayah</span>
                                </div>
                                <div class="mt-3 space-y-2">
                                    @foreach ($districts as $district)
                                        <div class="flex items-center gap-2">
                                            <span class="w-20 shrink-0 truncate text-[9px] text-slate-500">{{ $district['name'] }}</span>
                                            <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                                                <span class="block h-full rounded-full bg-brand-600" style="width: {{ round($district['students'] / $maxStudents * 100) }}%"></span>
                                            </span>
                                            <span class="w-10 shrink-0 text-right text-[9px] font-medium text-slate-600">{{ number_format($district['students'], 0, ',', '.') }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="rounded-xl border border-slate-100 p-3">
                                <p class="text-[10px] font-semibold text-slate-700">Catatan data</p>
                                <ul class="mt-3 space-y-2.5 text-[9px] leading-relaxed text-slate-500">
                                    <li class="flex items-start gap-1.5">
                                        <x-ui.icon name="check" class="mt-0.5 h-3 w-3 shrink-0 text-emerald-600" />
                                        <span>Agregat per wilayah</span>
                                    </li>
                                    <li class="flex items-start gap-1.5">
                                        <x-ui.icon name="check" class="mt-0.5 h-3 w-3 shrink-0 text-emerald-600" />
                                        <span>Tanpa nama, NISN, atau NIK</span>
                                    </li>
                                    <li class="flex items-start gap-1.5">
                                        <x-ui.icon name="check" class="mt-0.5 h-3 w-3 shrink-0 text-emerald-600" />
                                        <span>Sumber Dapodik</span>
                                    </li>
                                    <li class="flex items-start gap-1.5">
                                        <x-ui.icon name="check" class="mt-0.5 h-3 w-3 shrink-0 text-emerald-600" />
                                        <span>Diperbarui berkala</span>
                                    </li>
                                </ul>
                                <div class="mt-4 rounded-lg bg-brand-50 p-2.5">
                                    <p class="text-[9px] font-semibold text-brand-800">{{ $period }}</p>
                                    <p class="mt-0.5 text-[8px] text-brand-600">Ringkasan snapshot</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <figcaption class="mt-4 text-center text-xs leading-relaxed text-slate-500">
                Pratinjau antarmuka. Angka mengikuti ringkasan data {{ $period }}; tampilan sebenarnya dapat berbeda.
            </figcaption>
        </figure>

        <div class="mt-6 text-center">
            <x-ui.button :href="route('public.dashboard')" size="lg">
                Buka Dashboard Publik
                <x-ui.icon name="arrow-right" class="h-4 w-4" />
            </x-ui.button>
        </div>
    </div>
</section>
