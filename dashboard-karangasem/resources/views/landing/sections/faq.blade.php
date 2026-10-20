<section id="faq" class="section-pad scroll-mt-24 border-b border-brand-100/60 bg-sand-50">
    <div class="container-page">
        <div class="grid gap-10 lg:grid-cols-12 lg:gap-16">
            <div class="lg:col-span-5">
                <x-ui.section-heading
                    eyebrow="Pertanyaan umum"
                    title="Hal yang sering ditanyakan."
                    description="Belum menemukan jawaban? Hubungi kanal resmi Disdikpora Kabupaten Karangasem."
                />

                <div class="mt-7 rounded-2xl border border-brand-100 bg-white p-5 shadow-sm">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
                            <x-ui.icon name="mail" class="h-5 w-5" />
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-ink-950">Butuh bantuan lain?</p>
                            <p class="mt-1 text-sm leading-relaxed text-slate-600">Sampaikan pertanyaan atau permohonan akses melalui email resmi.</p>
                        </div>
                    </div>
                    <a href="mailto:{{ $organization['email'] }}" class="mt-4 inline-flex break-all rounded text-sm font-semibold text-brand-700 underline decoration-brand-200 underline-offset-4 hover:text-brand-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-700">
                        {{ $organization['email'] }}
                    </a>
                </div>
            </div>

            <div class="lg:col-span-7">
                <div class="divide-y divide-brand-100 rounded-2xl border border-brand-100 bg-white px-5 shadow-sm sm:px-6">
                    @foreach ($faq as $item)
                        <details class="group py-5" @if ($loop->first) open @endif>
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 rounded-lg text-left text-sm font-semibold text-ink-950 transition hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-700 [&::-webkit-details-marker]:hidden sm:text-base">
                                <span>{{ $item['question'] }}</span>
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700 transition group-open:rotate-45">
                                    <x-ui.icon name="plus" class="h-4 w-4" />
                                </span>
                            </summary>
                            <p class="mt-3 pr-10 text-sm leading-relaxed text-slate-600">
                                {{ $item['answer'] }}
                            </p>
                        </details>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>
