@php
    $steps = [
        [
            'title' => 'Buka dashboard publik',
            'description' => 'Akses dashboard tanpa login. Tidak ada formulir, tidak ada data pribadi yang diminta.',
        ],
        [
            'title' => 'Pilih data yang ingin dilihat',
            'description' => 'Telusuri ringkasan per kecamatan, jenjang, peserta didik, rombongan belajar, dan PTK.',
        ],
        [
            'title' => 'Gunakan untuk perencanaan',
            'description' => 'Bandingkan wilayah, temukan kebutuhan, lalu gunakan temuan itu sebagai bahan diskusi dan tindak lanjut.',
        ],
    ];
@endphp

<section class="section-pad border-b border-brand-100/60 bg-white">
    <div class="container-page">
        <div class="grid gap-10 lg:grid-cols-12 lg:gap-16">
            <div class="lg:col-span-5">
                <x-ui.section-heading
                    eyebrow="Cara mengakses"
                    title="Tiga langkah untuk mulai menjelajah."
                    description="PADMA dibuat agar informasi pendidikan dapat diakses cepat, termasuk lewat ponsel."
                />

                <div class="mt-7 rounded-2xl border border-gold-200 bg-gold-50 p-5">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gold-200/70 text-brand-900">
                            <x-ui.icon name="lock" class="h-5 w-5" />
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-brand-900">Butuh data yang lebih rinci?</p>
                            <p class="mt-1 text-sm leading-relaxed text-slate-600">
                                Ajukan akses VIP kepada admin Disdikpora. Akun VIP memiliki masa berlaku dan hanya diberikan kepada pengguna berwenang.
                            </p>
                            <a href="{{ route('login') }}" class="mt-3 inline-flex items-center gap-1.5 rounded text-sm font-semibold text-brand-800 underline decoration-gold-400 decoration-2 underline-offset-4 hover:text-brand-950 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-700">
                                Masuk ke area VIP
                                <x-ui.icon name="arrow-right" class="h-4 w-4" />
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-7">
                <ol class="relative space-y-4">
                    @foreach ($steps as $step)
                        <li class="flex gap-4 rounded-2xl border border-brand-100 bg-white p-5 shadow-sm sm:p-6">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-700 text-sm font-semibold text-white">
                                {{ $loop->iteration }}
                            </span>
                            <div>
                                <h3 class="text-base font-semibold text-ink-950">{{ $step['title'] }}</h3>
                                <p class="mt-1.5 text-sm leading-relaxed text-slate-600">{{ $step['description'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>

                <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                    <x-ui.button :href="route('public.dashboard')">
                        Mulai dari dashboard publik
                        <x-ui.icon name="arrow-right" class="h-4 w-4" />
                    </x-ui.button>
                    <x-ui.button href="#metodologi" variant="ghost">
                        Pelajari sumber data
                    </x-ui.button>
                </div>
            </div>
        </div>
    </div>
</section>
