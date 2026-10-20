@php
    $icons = ['school', 'users', 'layers', 'book-open'];
@endphp

<section class="border-b border-brand-100/60 bg-white py-10 sm:py-12" aria-label="Ringkasan data pendidikan">
    <div class="container-page">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($stats as $index => $stat)
                <x-ui.stat-card :stat="$stat" :icon="$icons[$index] ?? 'chart'" />
            @endforeach
        </div>

        @if ($snapshotStatus !== 'verified')
            <p class="mt-5 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-relaxed text-amber-900" role="status">
                <x-ui.icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                <span>
                    @if ($snapshotStatus === 'stale')
                        Snapshot statistik sudah melewati batas umur. Angka di atas dapat berubah setelah sinkronisasi Pusdatin dan review ulang.
                    @else
                        Snapshot statistik belum tersedia atau belum diverifikasi. Angka di atas masih nilai sementara dan belum boleh dijadikan dasar keputusan.
                    @endif
                </span>
            </p>
        @endif

        <p class="mt-5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs leading-relaxed text-slate-500">
            <x-ui.icon name="calendar" class="h-4 w-4 text-brand-600" />
            <span>Sumber: Dapodik, diolah PADMA. {{ $period }}. Terakhir diperbarui {{ $updatedAtDisplay }}. Angka dapat berubah mengikuti pemutakhiran data.</span>
        </p>
    </div>
</section>
