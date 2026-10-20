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
            'value' => 604,
            'display' => '604',
            'note' => 'satuan pendidikan aktif (formal & nonformal)',
        ],
        [
            'label' => 'Peserta Didik',
            'value' => 64086,
            'display' => '64.086',
            'note' => 'peserta didik unik (NISN valid 10 digit, kelas aktif)',
        ],
        [
            'label' => 'Rombongan Belajar',
            'value' => 3482,
            'display' => '3.482',
            'note' => 'kelas aktif pada semester terbaru',
        ],
        [
            'label' => 'Pendidik & Tenaga Kependidikan',
            'value' => 4044,
            'display' => '4.044',
            'note' => 'PTK aktif dengan penugasan induk',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sebaran kecamatan
    |--------------------------------------------------------------------------
    */

    'districts' => [
        ['name' => 'Karangasem', 'schools' => 115, 'rombel' => 724, 'students' => 13967, 'ptk' => 859],
        ['name' => 'Kubu', 'schools' => 80, 'rombel' => 527, 'students' => 10715, 'ptk' => 657],
        ['name' => 'Abang', 'schools' => 94, 'rombel' => 539, 'students' => 10465, 'ptk' => 608],
        ['name' => 'Manggis', 'schools' => 77, 'rombel' => 412, 'students' => 6903, 'ptk' => 445],
        ['name' => 'Bebandem', 'schools' => 74, 'rombel' => 377, 'students' => 6569, 'ptk' => 428],
        ['name' => 'Rendang', 'schools' => 60, 'rombel' => 334, 'students' => 5594, 'ptk' => 399],
        ['name' => 'Selat', 'schools' => 60, 'rombel' => 318, 'students' => 5634, 'ptk' => 387],
        ['name' => 'Sidemen', 'schools' => 44, 'rombel' => 251, 'students' => 4245, 'ptk' => 261],
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
