<?php

namespace App\Http\Controllers\Api;

use App\Support\Ads;
use Illuminate\Http\JsonResponse;

/**
 * Ad inventory and daily reports for the Stoop Admin desktop app (sales role, see Roles::API).
 * Same staff-and-admin-token check as AdminController (inherited).
 */
class AdsController extends AdminController
{
    public function inventory(): JsonResponse
    {
        return response()->json(['placements' => Ads::inventory(), 'csv_url' => route('admin.ads.csv')]);
    }

    public function report(string $kind, int $id): JsonResponse
    {
        abort_unless(Ads::find($kind, $id), 404);
        $days = Ads::report($kind, $id);

        return response()->json([
            'days' => $days,
            'impressions' => $days->sum('impressions'),
            'clicks' => $days->sum('clicks'),
            'ctr' => Ads::ctr($days->sum('clicks'), $days->sum('impressions')),
            'csv_url' => route('admin.ads.report', [$kind, $id]),
        ]);
    }
}
