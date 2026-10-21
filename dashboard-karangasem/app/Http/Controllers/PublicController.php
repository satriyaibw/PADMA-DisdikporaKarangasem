<?php

namespace App\Http\Controllers;

use App\Services\MetabaseEmbedService;
use Illuminate\View\View;

class PublicController extends Controller
{
    /**
     * Halaman publik dengan iframe public embed Metabase (tanpa token).
     */
    public function index(MetabaseEmbedService $metabase): View
    {
        return view('public.dashboard', [
            'embedUrl' => $this->withCacheBuster($metabase->publicUrl()),
        ]);
    }

    /**
     * Tambahkan penanda versi pada URL iframe publik.
     *
     * Tab browser yang dibiarkan terbuka menyimpan dokumen dashboard lama,
     * sehingga perubahan layout via API tidak terlihat sampai reload penuh.
     * Penanda ini membuat URL berubah setiap build/deploy, atau kapan pun
     * `ASSET_VERSION` dinaikkan.
     */
    private function withCacheBuster(string $url): string
    {
        $version = config('app.asset_version') ?: $this->buildTimestamp();

        if ($version === null) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'_v='.$version;
    }

    /**
     * Stempel waktu manifest Vite sebagai versi build. Null bila belum build.
     */
    private function buildTimestamp(): ?string
    {
        $manifest = public_path('build/manifest.json');

        return is_file($manifest) ? (string) filemtime($manifest) : null;
    }
}
