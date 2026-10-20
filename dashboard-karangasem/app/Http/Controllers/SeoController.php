<?php

namespace App\Http\Controllers;

use App\Services\LandingSeoService;
use Illuminate\Http\Response;

class SeoController extends Controller
{
    public function __construct(private LandingSeoService $seo) {}

    /**
     * robots.txt dinamis agar aturan indexability mengikuti konfigurasi.
     */
    public function robots(): Response
    {
        $content = config('seo.indexable')
            ? "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /login\nDisallow: /vip\n\nSitemap: ".$this->seo->homeUrl()."sitemap.xml\n"
            : "User-agent: *\nDisallow: /\n";

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Sitemap hanya berisi halaman yang benar-benar indexable.
     */
    public function sitemap(): Response
    {
        abort_unless(config('seo.indexable'), 404);

        return response()
            ->view('seo.sitemap', [
                'url' => $this->seo->homeUrl(),
                'lastmod' => $this->seo->meta()['updated_at'],
            ])
            ->withHeaders([
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Cache-Control' => 'public, max-age=3600',
            ]);
    }
}
