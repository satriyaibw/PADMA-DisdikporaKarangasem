<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RobotsHeaders
{
    /**
     * Lapisan kedua kebijakan indexability. Meta robots tetap ditulis di
     * halaman; header ini memastikan halaman tanpa layout khusus (admin,
     * login, VIP) juga tidak masuk indeks.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $value = $this->robotsValue($request);

        if ($value !== null) {
            $response->headers->set('X-Robots-Tag', $value);
        }

        return $response;
    }

    /**
     * Nilai X-Robots-Tag untuk request saat ini, atau null bila tidak perlu
     * ditambahkan.
     */
    protected function robotsValue(Request $request): ?string
    {
        if ($request->is('admin', 'admin/*', 'login', 'vip', 'vip/*')) {
            return 'noindex, nofollow';
        }

        if ($request->is('dashboard')) {
            return 'noindex, follow';
        }

        if ($request->is('/') && ! config('seo.indexable')) {
            return 'noindex, nofollow, noarchive';
        }

        return null;
    }
}
