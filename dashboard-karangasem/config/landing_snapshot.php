<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lokasi snapshot
    |--------------------------------------------------------------------------
    |
    | Snapshot dihasilkan dari query read-only setelah review, lalu dibaca
    | landing saat request. Jangan mengarahkan path ini ke file yang dapat
    | ditulis pengunjung.
    |
    */

    'path' => env('LANDING_SNAPSHOT_PATH', storage_path('app/landing/snapshot.json')),

    /*
    |--------------------------------------------------------------------------
    | Batas umur snapshot
    |--------------------------------------------------------------------------
    |
    | Dipakai oleh `landing:snapshot verify` untuk memperingatkan snapshot
    | yang sudah lama. Landing tetap menampilkan snapshot tersebut, tetapi
    | operator harus menjalankan ulang pipeline setelah sinkronisasi Pusdatin.
    |
    */

    'max_age_days' => (int) env('LANDING_SNAPSHOT_MAX_AGE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Jumlah kecamatan wajib
    |--------------------------------------------------------------------------
    |
    | Snapshot dianggap tidak valid bila jumlah kecamatan tidak sesuai. Nilai
    | ini menjaga agar query yang salah join tidak diam-diam menghilangkan
    | wilayah.
    |
    */

    'required_districts' => 8,

];
