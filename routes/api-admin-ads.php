<?php

use App\Http\Controllers\Api\AdsController as Ads;
use App\Support\Ads as AdSupport;
use Illuminate\Support\Facades\Route;

// Ad inventory and reports in the Stoop Admin desktop app. Required inside the /api/admin group.
Route::get('ads', [Ads::class, 'inventory']);
Route::get('ads/{kind}/{id}', [Ads::class, 'report'])->whereIn('kind', array_keys(AdSupport::KINDS))->whereNumber('id');
