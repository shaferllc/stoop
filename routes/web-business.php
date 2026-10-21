<?php

use App\Livewire\Admin;
use App\Livewire\Pages\BusinessAnalytics;
use App\Livewire\Pages\Promote;
use App\Livewire\Settings\Business as BusinessSettings;
use App\Models\PlaceDeal;
use App\Support\Business;
use Illuminate\Support\Facades\Route;

// Business accounts and self-serve promotions, paid through Stripe (test mode only).
// The Stripe webhook is in routes/api.php, away from sessions and CSRF.

// A deal card in a stop feed counts the click, then opens the place page. Never a link from the request.
Route::get('deals/{deal}/open', function (PlaceDeal $deal) {
    Business::count($deal->place_id, 'deal_clicks');

    return redirect()->route('places.show', $deal->place);
})->middleware('throttle:60,1')->name('deals.open');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/business', BusinessSettings::class)->name('business.edit');
    Route::post('business/upgrade', fn () => redirect()->away(Business::checkoutUrl(auth()->user())))->middleware('throttle:10,1')->name('business.upgrade');
    Route::post('business/billing', fn () => redirect()->away(Business::portalUrl(auth()->user())))->middleware('throttle:10,1')->name('business.billing');
    Route::livewire('business/analytics/{place}', BusinessAnalytics::class)->name('business.analytics');
    Route::livewire('promote/{kind}/{id}', Promote::class)->whereIn('kind', ['deal', 'dispatch'])->whereNumber('id')->name('promote');
    Route::livewire('admin/business', Admin\Business::class)->name('admin.business');
});
