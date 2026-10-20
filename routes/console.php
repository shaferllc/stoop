<?php

use App\Actions\AnnounceDispatch;
use App\Models\Dispatch;
use App\Support\Civility;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('stoop:publish-scheduled', function () {
    Dispatch::where('status', 'scheduled')->where('publish_at', '<=', now())->each(function (Dispatch $dispatch) {
        $dispatch->forceFill([
            'status' => Civility::shouldHold($dispatch->body) ? 'held' : 'approved',
            'created_at' => $dispatch->publish_at,
        ])->save();
        AnnounceDispatch::run($dispatch);
    });
})->purpose('Publish scheduled dispatches whose time has come');

Artisan::command('stoop:purge-deleted', function () {
    // After the undo window, erase deleted dispatches. Ones with replies keep an empty tombstone row.
    Dispatch::onlyTrashed()->where('deleted_at', '<', now()->subMinute())->where('body', '!=', '')
        ->each(function (Dispatch $dispatch) {
            $dispatch->images->each(fn ($image) => Storage::disk('public')->delete($image->path));
            $dispatch->images()->delete();

            Dispatch::withTrashed()->where('parent_id', $dispatch->id)->exists()
                ? $dispatch->forceFill(['body' => ''])->saveQuietly()
                : $dispatch->forceDelete();
        });
})->purpose('Erase deleted dispatches once the undo window has passed');

Schedule::command('stoop:publish-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('stoop:purge-deleted')->everyMinute()->withoutOverlapping();
