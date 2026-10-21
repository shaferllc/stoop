<?php

namespace App\Livewire\Admin;

use App\Support\Audit;
use App\Support\MagazineEvents as Events;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The magazine's things-to-do listings as Happenings: last import, "Import now", and a CSV
 * upload for listings the feed can't read.
 */
#[Layout('layouts::stoop')]
#[Title('Magazine events · Admin')]
class MagazineEvents extends Component
{
    use StaffOnly, WithFileUploads;

    public const ROLE = 'editor';

    public $csv;

    public function importNow(): void
    {
        if (! RateLimiter::attempt('magazine-events:'.$this->staff()->id, 5, fn () => true)) {
            Flux::toast('Give the last import a minute.', variant: 'danger');

            return;
        }

        $this->report(Events::import(), 'feed');
    }

    public function upload(): void
    {
        $this->validate(['csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        $this->report(Events::importCsv($this->csv->getRealPath()), 'csv');
        $this->reset('csv');
    }

    /**
     * @param  array{posted: int, updated: int, skipped: int, error: ?string}  $result
     */
    protected function report(array $result, string $source): void
    {
        Audit::log('magazine_events.imported', null, ['source' => $source, ...$result]);
        $result['error']
            ? Flux::toast($result['error'], variant: 'danger')
            : Flux::toast("Posted {$result['posted']}, updated {$result['updated']}, skipped {$result['skipped']}.");
    }

    public function render(): View
    {
        return view('livewire.admin.magazine-events', [
            'status' => Events::status(),
            'events' => DB::table('magazine_events')->latest('id')->take(30)->get(),
        ]);
    }
}
