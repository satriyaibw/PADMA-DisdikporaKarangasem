<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flag
    |--------------------------------------------------------------------------
    |
    | Saat dimatikan, `/` langsung dialihkan ke dashboard publik. Ini adalah
    | jalur rollback cepat tanpa perlu mengubah route atau melakukan deploy
    | ulang kode.
    |
    */

    'enabled' => (bool) env('LANDING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Metadata halaman
    |--------------------------------------------------------------------------
    |
    | Seluruh nilai di bawah ini dipakai oleh LandingSeoService dan komponen
    | SEO. Jangan menambahkan query database di landing: halaman ini sengaja
    | stateless dan dapat di-cache.
    |
    */

    'meta' => [
        'title' => 'PADMA Karangasem — Data Pendidikan Kabupaten Karangasem',
        'description' => 'Jelajahi data pendidikan Kabupaten Karangasem di PADMA: satuan pendidikan, peserta didik, rombongan belajar, dan PTK dalam satu dashboard terbuka.',
        'og_image_alt' => 'PADMA Karangasem — data pendidikan Kabupaten Karangasem',
        'updated_at' => '2026-10-20T00:00:00+08:00',
        'updated_at_display' => '20 Oktober 2026',
        'period' => 'Semester Ganjil 2026/2027',
    ],

    /*
    |--------------------------------------------------------------------------
    | Entitas resmi
    |--------------------------------------------------------------------------
    */

    'organization' => [
        'name' => 'Dinas Pendidikan Kepemudaan dan Olahraga Kabupaten Karangasem',
        'short_name' => 'Disdikpora Karangasem',
        'url' => 'https://pendidikan.karangasemkab.go.id',
        'logo' => 'images/branding/padma-mark-512.png',
        'telephone' => '+62-363-2787724',
        'telephone_display' => '(0363) 2787724',
        'email' => 'admin@pendidikan.karangasemkab.go.id',
        'address' => [
            'street' => 'Jalan Veteran',
            'locality' => 'Amlapura',
            'region' => 'Bali',
            'country' => 'ID',
            'display' => 'Jalan Veteran, Amlapura, Bali',
        ],
        'same_as' => [
            'https://www.facebook.com/disdikporakarangasem/',
            'https://www.instagram.com/disdikpora.karangasem/',
            'https://www.tiktok.com/@disdikporakarangasem',
            'https://www.youtube.com/@disdikporakarangasem6374',
        ],
        'area_served' => 'Kabupaten Karangasem',
        'knows_about' => [
            'Data pendidikan',
            'Dapodik',
            'Satuan pendidikan',
            'Peserta didik',
            'Rombongan belajar',
            'Pendidik dan tenaga kependidikan',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Snapshot statistik
    |--------------------------------------------------------------------------
    |
    | Angka-angka ini adalah snapshot data Dapodik, bukan hasil query live.
    | Nilai final menunggu verifikasi/sign-off; perbarui bersama konten, lalu
    | deploy ulang.
    |
    */

    'stats' => [
        [
            'label' => 'Satuan Pendidikan',
            'value' => 843,
            'display' => '843',
            'note' => 'SD, SMP, SMA, SMK, dan SLB',
        ],
        [
            'label' => 'Peserta Didik',
            'value' => 68307,
            'display' => '68.307',
            'note' => 'peserta didik unik',
        ],
        [
            'label' => 'Rombongan Belajar',
            'value' => 5077,
            'display' => '5.077',
            'note' => 'kelas dan kelompok belajar',
        ],
        [
            'label' => 'Pendidik & Tenaga Kependidikan',
            'value' => 5105,
            'display' => '5.105',
            'note' => 'PTK terdaftar',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sebaran kecamatan
    |--------------------------------------------------------------------------
    */

    'districts' => [
        ['name' => 'Karangasem', 'schools' => 159, 'rombel' => 1063, 'students' => 14928],
        ['name' => 'Kubu', 'schools' => 113, 'rombel' => 757, 'students' => 11362],
        ['name' => 'Abang', 'schools' => 146, 'rombel' => 789, 'students' => 10933],
        ['name' => 'Manggis', 'schools' => 99, 'rombel' => 538, 'students' => 7330],
        ['name' => 'Bebandem', 'schools' => 99, 'rombel' => 586, 'students' => 7024],
        ['name' => 'Rendang', 'schools' => 77, 'rombel' => 519, 'students' => 6215],
        ['name' => 'Selat', 'schools' => 92, 'rombel' => 475, 'students' => 6128],
        ['name' => 'Sidemen', 'schools' => 58, 'rombel' => 350, 'students' => 4402],
    ],

    /*
    |--------------------------------------------------------------------------
    | FAQ
    |--------------------------------------------------------------------------
    |
    | Array yang sama dipakai untuk konten yang terlihat dan FAQPage JSON-LD.
    | Jangan menduplikasi pertanyaan di view.
    |
    */

    'faq' => [
        [
            'question' => 'Apa itu PADMA?',
            'answer' => 'PADMA (Portal Analisis Dapodik menuju Karangasem Agung) adalah portal visualisasi data pendidikan Kabupaten Karangasem yang dikelola Dinas Pendidikan Kepemudaan dan Olahraga Kabupaten Karangasem.',
        ],
        [
            'question' => 'Data apa yang disajikan PADMA?',
            'answer' => 'PADMA menyajikan agregat satuan pendidikan, peserta didik, rombongan belajar, pendidik dan tenaga kependidikan (PTK), serta sebaran data per kecamatan di Kabupaten Karangasem.',
        ],
        [
            'question' => 'Apakah dashboard publik dapat diakses gratis?',
            'answer' => 'Ya. Dashboard publik dapat diakses tanpa login. Data yang lebih rinci memerlukan akun VIP yang diaktifkan oleh admin Disdikpora.',
        ],
        [
            'question' => 'Seberapa sering data diperbarui?',
            'answer' => 'Data mengikuti pemutakhiran Dapodik yang tersedia pada sistem. Periode data ditampilkan pada dashboard; data tidak diperbarui secara real-time.',
        ],
        [
            'question' => 'Apakah data pribadi ditampilkan?',
            'answer' => 'Dashboard publik hanya menampilkan data agregat dan tidak memuat nama, NISN, NIK, atau informasi pribadi lainnya. Data rinci hanya untuk pengguna berwenang.',
        ],
        [
            'question' => 'Bagaimana cara memperoleh akses VIP?',
            'answer' => 'Ajukan permohonan kepada Disdikpora Kabupaten Karangasem melalui kontak resmi. Akun VIP diaktifkan oleh admin dan memiliki masa berlaku tertentu.',
        ],
    ],

];
