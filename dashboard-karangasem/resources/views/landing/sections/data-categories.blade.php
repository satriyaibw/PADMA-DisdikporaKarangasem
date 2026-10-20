@php
    $categories = [
        [
            'title' => 'Satuan Pendidikan',
            'value' => $stats[0]['display'],
            'unit' => 'satuan',
            'description' => 'Profil satuan pendidikan aktif di seluruh Kabupaten Karangasem.',
            'icon' => 'school',
        ],
        [
            'title' => 'Peserta Didik',
            'value' => $stats[1]['display'],
            'unit' => 'peserta didik unik',
            'description' => 'Jumlah peserta didik aktif yang tercatat pada rombongan belajar di setiap wilayah.',
            'icon' => 'users',
        ],
        [
            'title' => 'Rombongan Belajar',
            'value' => $stats[2]['display'],
            'unit' => 'rombel',
            'description' => 'Sebaran kelas dan kelompok belajar sebagai gambaran kapasitas layanan pendidikan.',
            'icon' => 'layers',
        ],
        [
            'title' => 'Pendidik & Tenaga Kependidikan',
            'value' => $stats[3]['display'],
            'unit' => 'PTK unik',
            'description' => 'Guru, kepala sekolah, pengawas, dan tenaga kependidikan yang mendukung satuan pendidikan.',
            'icon' => 'book-open',
        ],
        [
            'title' => 'Sebaran Wilayah',
            'value' => '8',
            'unit' => 'kecamatan',
            'description' => 'Potret pendidikan dari Karangasem, Kubu, Abang, Manggis, Bebandem, Rendang, Selat, dan Sidemen.',
            'icon' => 'map-pin',
        ],
    ];
@endphp

<section id="data" class="section-pad scroll-mt-24 border-b border-brand-100/60 bg-sand-50">
    <div class="container-page">
        <x-ui.section-heading
            align="center"
            eyebrow="Kategori data"
            title="Data yang Anda butuhkan, dalam satu tampilan."
            description="PADMA menyusun data Dapodik ke dalam kategori yang mudah dipahami, sehingga Anda dapat melihat gambaran besar tanpa kehilangan detail penting."
        />

        <div class="mt-10 grid gap-4 md:grid-cols-2 lg:grid-cols-6">
            @foreach ($categories as $category)
                <article class="flex h-full flex-col rounded-2xl border border-brand-100 bg-white p-6 shadow-sm transition hover:border-brand-200 hover:shadow-md {{ $loop->index < 3 ? 'lg:col-span-2' : 'lg:col-span-3' }}">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
                        <x-ui.icon :name="$category['icon']" class="h-5 w-5" />
                    </span>

                    <h3 class="mt-5 text-base font-semibold text-ink-950">{{ $category['title'] }}</h3>

                    <p class="mt-3 flex items-baseline gap-2">
                        <data value="{{ $category['value'] }}" class="text-3xl font-semibold tracking-tight text-brand-800">{{ $category['value'] }}</data>
                        <span class="text-xs font-medium text-slate-500">{{ $category['unit'] }}</span>
                    </p>

                    <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ $category['description'] }}</p>
                </article>
            @endforeach
        </div>

        <p class="mt-6 flex items-start gap-2 text-xs leading-relaxed text-slate-500">
            <x-ui.icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
            <span>Angka pada halaman ini merupakan ringkasan agregat. Data rinci per satuan pendidikan tersedia melalui dashboard.</span>
        </p>
    </div>
</section>
