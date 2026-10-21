<?php

use App\Livewire\Admin;
use App\Livewire\Pages\Classifieds as ClassifiedsPage;
use App\Livewire\Pages\ListingShow;
use App\Models\Area;
use App\Models\Listing;
use App\Models\Place;
use App\Support\Ads;
use App\Support\AreaReports;
use App\Support\Audit;
use App\Support\Classifieds;
use App\Support\Referrals;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

// Reservation and ticket referrals, classifieds, and neighborhood area reports.

// "Reserve" counts the click, then sends people to the place's stored reservation link only.
Route::get('places/{place}/reserve', fn (Place $place) => Referrals::reserve($place))->middleware('throttle:30,1')->name('places.reserve');

// Classifieds. Back from Stripe Checkout, Stripe is asked whether the listing is paid.
Route::livewire('classifieds', ClassifiedsPage::class)->name('classifieds');
Route::livewire('classifieds/{listing}', ListingShow::class)->whereNumber('listing')->name('classifieds.show');
Route::get('classifieds/{listing}/paid', function (Listing $listing) {
    abort_unless($listing->user_id === auth()->id(), 403);
    $paid = Classifieds::confirm($listing, (string) request('session_id'));

    return redirect()->route('classifieds.show', $listing)->with('status', $paid ? 'Paid. Thanks!' : 'We couldn’t confirm the payment yet.');
})->whereNumber('listing')->middleware(['auth', 'throttle:20,1'])->name('classifieds.paid');

// An area report shared with a client who has no Stoop login: the signature covers the area
// and month, and the link expires (see Admin\Areas::share).
Route::get('reports/areas/{area}/{month}', fn (Area $area, string $month) => view('admin.area-report', ['report' => AreaReports::report($area, $month)]))
    ->where('month', '\d{4}-\d{2}')->middleware(['signed', 'throttle:30,1'])->name('areas.shared');

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('referrals', Admin\Referrals::class)->name('referrals');
    Route::livewire('classifieds', Admin\Listings::class)->name('listings');
    Route::get('referrals.csv', function () {
        abort_unless(auth()->user()->hasRole(Roles::PAGES['admin.referrals.csv']), 403);
        [$from, $to] = Admin\Referrals::period((string) request('from'), (string) request('to'));
        Audit::log('referrals.exported', null, compact('from', 'to'));

        return response(Ads::csv([['day', 'provider', 'clicks'], ...Referrals::byDay($from, $to)->map(fn ($r) => [$r->day, $r->provider, $r->clicks])]), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"stoop-referrals-$from-$to.csv\"",
        ]);
    })->middleware('throttle:20,1')->name('referrals.csv');

    Route::livewire('areas', Admin\Areas::class)->name('areas');
    Route::get('areas/{area}/{month}/print', function (Area $area, string $month) {
        abort_unless(auth()->user()->hasRole(Roles::PAGES['admin.areas.print']), 403);

        return view('admin.area-report', ['report' => AreaReports::report($area, $month), 'staff' => true]);
    })->where('month', '\d{4}-\d{2}')->middleware('throttle:20,1')->name('areas.print');
    Route::get('areas/{area}/{month}.csv', function (Area $area, string $month) {
        abort_unless(auth()->user()->hasRole(Roles::PAGES['admin.areas.csv']), 403);
        Audit::log('area_report.exported', null, ['area' => $area->name, 'month' => $month]);

        return response(Ads::csv(AreaReports::csvRows(AreaReports::report($area, $month))), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="stoop-area-'.$area->slug.'-'.$month.'.csv"',
        ]);
    })->where('month', '\d{4}-\d{2}')->middleware('throttle:20,1')->name('areas.csv');
});
