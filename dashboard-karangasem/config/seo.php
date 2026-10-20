<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Indexability
    |--------------------------------------------------------------------------
    |
    | Default `false` agar tunnel pengembangan dan URL sementara tidak pernah
    | diindeks. Aktifkan hanya setelah domain final, konten, dan statistik
    | disetujui.
    |
    */

    'indexable' => (bool) env('SEO_INDEXABLE', false),

    /*
    |--------------------------------------------------------------------------
    | Verifikasi mesin pencari
    |--------------------------------------------------------------------------
    */

    'google_site_verification' => env('GOOGLE_SITE_VERIFICATION'),
    'bing_site_verification' => env('BING_SITE_VERIFICATION'),

    /*
    |--------------------------------------------------------------------------
    | Open Graph
    |--------------------------------------------------------------------------
    */

    'default_og_image' => 'images/og/padma-karangasem-1200x630.jpg',

];
