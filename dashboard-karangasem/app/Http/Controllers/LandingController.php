<?php

namespace App\Http\Controllers;

use App\Services\LandingSeoService;
use App\Services\LandingSnapshotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class LandingController extends Controller
{
    /**
     * Landing publik yang stateless dan dapat di-cache.
     */
    public function __invoke(
        LandingSeoService $seo,
        LandingSnapshotService $snapshots,
    ): Response|RedirectResponse {
        if (! config('landing.enabled')) {
            return redirect()->route('public.dashboard');
        }

        $snapshot = $snapshots->load();
        $organization = config('landing.organization');
        $snapshotStatus = match (true) {
            $snapshot === null => 'unverified',
            $snapshots->isStale($snapshot) => 'stale',
            default => 'verified',
        };

        return response()
            ->view('landing.index', [
                'meta' => $seo->meta(),
                'schema' => $seo->schema(),
                'stats' => $snapshots->stats($snapshot),
                'districts' => $snapshots->districts($snapshot),
                'faq' => config('landing.faq'),
                'organization' => $organization,
                'updatedAtDisplay' => $snapshots->updatedAtDisplay($snapshot)
                    ?? config('landing.meta.updated_at_display'),
                'period' => $snapshots->periodLabel($snapshot)
                    ?? config('landing.meta.period'),
                'snapshotStatus' => $snapshotStatus,
            ])
            ->withHeaders([
                'Cache-Control' => 'public, max-age=300, s-maxage=3600, stale-while-revalidate=86400',
                'Content-Language' => 'id',
            ]);
    }
}
