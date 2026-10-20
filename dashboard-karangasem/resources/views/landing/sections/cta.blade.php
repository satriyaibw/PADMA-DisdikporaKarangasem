<section class="bg-white py-16 sm:py-20 lg:py-24">
    <div class="container-page">
        <div class="relative overflow-hidden rounded-[2rem] bg-gradient-to-br from-brand-950 via-brand-900 to-brand-700 px-6 py-12 shadow-2xl shadow-brand-900/20 sm:px-10 sm:py-14 lg:px-16 lg:py-16">
            <div class="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full border border-white/10" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -bottom-24 -left-12 h-72 w-72 rounded-full bg-gold-400/10 blur-2xl" aria-hidden="true"></div>
            <div class="pointer-events-none absolute right-16 top-16 h-24 w-24 rounded-full bg-gold-300/10 blur-xl" aria-hidden="true"></div>

            <div class="relative mx-auto flex max-w-3xl flex-col items-center text-center">
                <p class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3.5 py-1.5 text-[11px] font-semibold text-gold-200">
                    <x-ui.icon name="sparkles" class="h-4 w-4" />
                    Portal resmi Disdikpora Kabupaten Karangasem
                </p>

                <h2 class="mt-6 text-3xl font-semibold leading-tight tracking-tight text-white sm:text-4xl">
                    Mulai jelajahi data pendidikan Karangasem.
                </h2>
                <p class="mt-4 max-w-2xl text-sm leading-relaxed text-brand-100 sm:text-base">
                    Akses dashboard publik tanpa login, atau masuk ke area VIP jika Anda memiliki akun resmi. Satu pintu untuk data yang lebih terbuka dan keputusan yang lebih baik.
                </p>

                <div class="mt-8 flex w-full flex-col justify-center gap-3 sm:w-auto sm:flex-row">
                    <x-ui.button :href="route('public.dashboard')" variant="white" size="lg">
                        <x-ui.icon name="dashboard" class="h-4 w-4" />
                        Buka Dashboard Publik
                        <x-ui.icon name="arrow-right" class="h-4 w-4" />
                    </x-ui.button>
                    <x-ui.button :href="route('login')" variant="outline-light" size="lg">
                        Login VIP
                    </x-ui.button>
                </div>

                <p class="mt-5 flex items-center gap-1.5 text-[11px] text-brand-300">
                    <x-ui.icon name="lock" class="h-3.5 w-3.5" />
                    Dashboard publik tidak memerlukan akun dan tidak menampilkan data pribadi.
                </p>
            </div>
        </div>
    </div>
</section>
