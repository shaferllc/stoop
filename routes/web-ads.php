<?php

use App\Livewire\Admin;
use App\Support\Ads;
use App\Support\Audit;
use Illuminate\Support\Facades\Route;

// Native ads: click-throughs, and the sales inventory and reports.

// Counts the click, then sends people to the ad's stored link. Never a link from the request.
Route::get('ad/{kind}/{id}', function (string $kind, int $id) {
    $ad = Ads::find($kind, $id);
    abort_unless($ad && $kind !== 'sponsorship', 404);
    Ads::click($ad);

    return redirect()->away($ad->url);
})->whereIn('kind', ['dispatch', 'party'])->whereNumber('id')->middleware('throttle:60,1')->name('ads.click');

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin')->group(function () {
    Route::livewire('ads', Admin\Ads::class)->name('.ads');
    Route::get('ads.csv', function () {
        abort_unless(auth()->user()->hasRole('sales'), 403);
        Audit::log('ads.exported');
        $head = ['type', 'name', 'where', 'status', 'starts', 'ends', 'impressions', 'goal', 'clicks', 'ctr_percent', 'hides', 'reports'];

        return response(Ads::csv([$head, ...Ads::inventory()->map(fn ($r) => [$r['type'], $r['name'], $r['where'], $r['status'], $r['starts'], $r['ends'], $r['impressions'], $r['goal'], $r['clicks'], $r['ctr'], $r['hides'], $r['reports']])]), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="stoop-ad-inventory.csv"',
        ]);
    })->middleware('throttle:20,1')->name('.ads.csv');
    Route::get('ads/{kind}/{id}.csv', function (string $kind, int $id) {
        abort_unless(auth()->user()->hasRole('sales'), 403);
        abort_unless(Ads::find($kind, $id), 404);

        return response(Ads::csv([['day', 'impressions', 'clicks', 'ctr_percent'], ...Ads::report($kind, $id)->map(fn ($r) => [$r->day, $r->impressions, $r->clicks, $r->ctr])]), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"stoop-ad-$kind-$id.csv\"",
        ]);
    })->whereIn('kind', array_keys(Ads::KINDS))->whereNumber('id')->middleware('throttle:20,1')->name('.ads.report');
});
