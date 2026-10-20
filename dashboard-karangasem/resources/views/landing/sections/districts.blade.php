@php
    $maxStudents = max(array_column($districts, 'students'));
@endphp

<section id="kecamatan" class="section-pad scroll-mt-24 border-b border-brand-100/60 bg-white">
    <div class="container-page">
        <div class="flex flex-col justify-between gap-6 lg:flex-row lg:items-end">
            <x-ui.section-heading
                eyebrow="Cakupan wilayah"
                title="Delapan kecamatan, satu gambaran pendidikan."
                description="Lihat sebaran satuan pendidikan, rombongan belajar, dan peserta didik di setiap kecamatan Kabupaten Karangasem."
            />

            <div class="shrink-0">
                <x-ui.button :href="route('public.dashboard')" variant="secondary">
                    Lihat data lengkap
                    <x-ui.icon name="arrow-right" class="h-4 w-4" />
                </x-ui.button>
            </div>
        </div>

        <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($districts as $district)
                <article class="group flex h-full flex-col rounded-2xl border border-brand-100 bg-white p-5 shadow-sm transition hover:border-brand-200 hover:shadow-md">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                                <x-ui.icon name="map-pin" class="h-4 w-4" />
                            </span>
                            <h3 class="text-sm font-semibold text-ink-950">{{ $district['name'] }}</h3>
                        </div>
                        <span class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">Kec.</span>
                    </div>

                    <p class="mt-5 flex items-baseline gap-1.5">
                        <data value="{{ $district['students'] }}" class="text-2xl font-semibold tracking-tight text-brand-900">
                            {{ number_format($district['students'], 0, ',', '.') }}
                        </data>
                        <span class="text-[11px] font-medium text-slate-500">peserta didik</span>
                    </p>

                    <dl class="mt-4 grid grid-cols-2 gap-2 border-t border-brand-50 pt-3 text-[11px]">
                        <div>
                            <dt class="text-slate-500">Satuan pendidikan</dt>
                            <dd class="mt-0.5 font-semibold text-slate-700">
                                <data value="{{ $district['schools'] }}">{{ number_format($district['schools'], 0, ',', '.') }}</data>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Rombel</dt>
                            <dd class="mt-0.5 font-semibold text-slate-700">
                                <data value="{{ $district['rombel'] }}">{{ number_format($district['rombel'], 0, ',', '.') }}</data>
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-brand-50" aria-hidden="true">
                        <div class="h-full rounded-full bg-gradient-to-r from-brand-600 to-gold-400" style="width: {{ round($district['students'] / $maxStudents * 100) }}%"></div>
                    </div>
                </article>
            @endforeach
        </div>

        @if (($snapshotStatus ?? 'unverified') !== 'verified')
            <p class="mt-5 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-relaxed text-amber-900" role="status">
                <x-ui.icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                <span>Angka kecamatan masih memakai nilai sementara dan belum diverifikasi.</span>
            </p>
        @endif

        <p class="mt-5 flex items-start gap-2 text-xs leading-relaxed text-slate-500">
            <x-ui.icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
            <span>Panjang batang menunjukkan proporsi peserta didik antarwilayah. Angka merupakan snapshot {{ $period }} dan dapat berubah mengikuti pemutakhiran Dapodik. Karena peserta didik dapat tercatat di lebih dari satu rombel, penjumlahan per kecamatan dapat sedikit berbeda dari total peserta didik unik.</span>
        </p>
    </div>
</section>
