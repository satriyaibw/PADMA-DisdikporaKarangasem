@php
    $features = [
        [
            'title' => 'Transparansi & akuntabilitas',
            'description' => 'Membuka arus data pendidikan daerah secara terstruktur, sehingga informasi publik dan pertanggungjawaban tata kelola berjalan searah.',
            'icon' => 'shield-check',
        ],
        [
            'title' => 'Perencanaan berbasis fakta',
            'description' => 'Membantu merumuskan alokasi program, bantuan, dan intervensi berdasarkan kebutuhan nyata di lapangan — bukan asumsi.',
            'icon' => 'target',
        ],
        [
            'title' => 'Integrasi multi-sumber',
            'description' => 'Menyatukan data pokok pendidikan dari berbagai jenjang dan wilayah dalam satu kontrak data yang konsisten.',
            'icon' => 'network',
        ],
        [
            'title' => 'Pemerataan kualitas',
            'description' => 'Mengidentifikasi kesenjangan antarkecamatan agar distribusi sumber daya pendidikan lebih adil dan tepat sasaran.',
            'icon' => 'scale',
        ],
        [
            'title' => 'Data terverifikasi',
            'description' => 'Setiap angka melewati tahapan validasi dan pemutakhiran. Periode serta sumber data ditampilkan terbuka di dashboard.',
            'icon' => 'database',
        ],
        [
            'title' => 'Akses yang mudah',
            'description' => 'Dashboard publik dapat dibuka tanpa login. Data rinci tersedia bagi pengguna berwenang melalui area VIP.',
            'icon' => 'compass',
        ],
    ];
@endphp

<section class="section-pad border-b border-white/10 bg-ink-950">
    <div class="container-page">
        <x-ui.section-heading
            align="center"
            light
            eyebrow="Mengapa PADMA"
            title="Data yang membantu keputusan lebih baik."
            description="PADMA bukan sekadar kumpulan angka. Ia menghubungkan data, kebijakan, dan layanan pendidikan dalam satu ekosistem informasi yang terarah."
        />

        <div class="mt-10 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($features as $feature)
                <article class="flex h-full flex-col rounded-2xl border border-white/10 bg-white/5 p-6 transition hover:border-gold-300/30 hover:bg-white/[0.07]">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-gold-300/10 text-gold-300">
                        <x-ui.icon :name="$feature['icon']" class="h-5 w-5" />
                    </span>
                    <h3 class="mt-5 text-base font-semibold text-white">{{ $feature['title'] }}</h3>
                    <p class="mt-3 text-sm leading-relaxed text-brand-200">{{ $feature['description'] }}</p>
                </article>
            @endforeach
        </div>

        <p class="mx-auto mt-8 max-w-3xl text-center text-xs leading-relaxed text-brand-300">
            Dirancang untuk pengambil kebijakan, kepala sekolah, pengawas, operator satuan pendidikan, peneliti, dan masyarakat.
        </p>
    </div>
</section>
