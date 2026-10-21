<?php

namespace App\Livewire\Admin;

use App\Bots\History;
use App\Support\Audit;
use App\Support\ClaudeText;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The "On this day in DC" list @history posts from.
 */
#[Layout('layouts::stoop')]
#[Title('History · Admin')]
class HistoryEntries extends Component
{
    use StaffOnly;

    public ?int $editing = null;

    public int $month = 1;

    public int $day = 1;

    public string $text = '';

    public string $link = '';

    public string $line = '';

    public string $stop = '';

    public function mount(): void
    {
        $today = CarbonImmutable::now(config('stoop.timezone'));
        [$this->month, $this->day] = [$today->month, $today->day];
    }

    public function save(): void
    {
        $this->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'day' => ['required', 'integer', 'between:1,31', fn ($a, $v, $fail) => checkdate($this->month, (int) $v, 2000) || $fail('That date doesn’t exist.')],
            'text' => ['required', 'string', 'min:10', 'max:400'],
            'link' => ['nullable', 'url:https,http', 'max:300'],
            'line' => ['nullable', Rule::in(array_keys(config('stoop.lines')))],
            'stop' => ['nullable', Rule::in(array_keys(config('stoop.stations')))],
        ]);

        // Saving is the editor's sign-off, so a saved entry no longer needs verifying.
        $row = ['month' => $this->month, 'day' => $this->day, 'text' => trim($this->text), 'link' => $this->link ?: null,
            'line' => $this->line ?: null, 'stop' => $this->stop ?: null, 'verify' => false, 'updated_at' => now()];

        if ($this->editing) {
            DB::table('history_entries')->where('id', $this->editing)->update($row);
            Audit::log('history.updated', null, ['id' => $this->editing]);
        } else {
            $id = DB::table('history_entries')->insertGetId([...$row, 'created_at' => now()]);
            Audit::log('history.added', null, ['id' => $id]);
        }

        $this->reset('editing', 'text', 'link', 'line', 'stop');
        Flux::toast('Saved.');
    }

    public function edit(int $id): void
    {
        $entry = DB::table('history_entries')->find($id) ?? abort(404);
        $this->editing = $id;
        [$this->month, $this->day, $this->text, $this->link, $this->line, $this->stop] = [$entry->month, $entry->day, $entry->text, (string) $entry->link, (string) $entry->line, (string) $entry->stop];
    }

    public function cancel(): void
    {
        $this->reset('editing', 'text', 'link', 'line', 'stop');
    }

    public function verify(int $id): void
    {
        DB::table('history_entries')->where('id', $id)->update(['verify' => false, 'updated_at' => now()]);
        Audit::log('history.verified', null, ['id' => $id]);
        Flux::toast('Marked as checked. It will post on its day.');
    }

    public function delete(int $id): void
    {
        DB::table('history_entries')->where('id', $id)->delete();
        Audit::log('history.deleted', null, ['id' => $id]);
    }

    /**
     * Claude drafts the text field from the date (and anything typed as a hint). It only
     * fills the form; nothing is saved until an editor checks it and saves.
     */
    public function draft(): void
    {
        if (! ClaudeText::enabled()) {
            return;
        }

        if (! RateLimiter::attempt('history-draft:'.$this->staff()->id, 10, fn () => true)) {
            Flux::toast('Too many drafts. Try again in a minute.');

            return;
        }

        if (! checkdate($this->month, $this->day, 2000)) {
            return;
        }

        $draft = History::draft($this->month, $this->day, trim($this->text));
        $draft ? $this->text = $draft : Flux::toast('Claude didn’t come up with anything. Try a topic hint.');
    }

    public function render(): View
    {
        return view('livewire.admin.history-entries', [
            'entries' => DB::table('history_entries')->orderBy('month')->orderBy('day')->get(),
            'ai' => ClaudeText::enabled(),
        ]);
    }
}
