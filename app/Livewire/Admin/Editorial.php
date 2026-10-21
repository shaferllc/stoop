<?php

namespace App\Livewire\Admin;

use App\Models\Ballot;
use App\Models\EditorThread;
use App\Models\Place;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * "Ask the editor" threads and Best of Washington reader ballots.
 */
#[Layout('layouts::stoop')]
#[Title('Editorial · Admin')]
class Editorial extends Component
{
    use StaffOnly;

    public const ROLE = 'editor';

    public string $amaTitle = '';

    public string $amaStarts = '';

    public string $amaEnds = '';

    public string $ballotTitle = '';

    public string $ballotOpens = '';

    public string $ballotCloses = '';

    /** One category per line: "Best pizza: place-slug, Another Place Name". */
    public string $ballotCategories = '';

    public function openAma(): void
    {
        $this->validate([
            'amaTitle' => ['required', 'string', 'max:160'],
            'amaStarts' => ['required', 'date'],
            'amaEnds' => ['required', 'date', 'after:amaStarts', 'after:now'],
        ], [], ['amaTitle' => 'title', 'amaStarts' => 'start', 'amaEnds' => 'end']);

        $tz = config('stoop.timezone');
        $thread = EditorThread::open($this->staff(), $this->amaTitle, Carbon::parse($this->amaStarts, $tz)->utc(), Carbon::parse($this->amaEnds, $tz)->utc());
        Audit::log('ama.opened', $thread, ['title' => $thread->title]);
        $this->reset('amaTitle', 'amaStarts', 'amaEnds');
        Flux::toast('Thread posted. It’s in the feed now.');
    }

    public function createBallot(): void
    {
        $this->validate([
            'ballotTitle' => ['required', 'string', 'max:160'],
            'ballotOpens' => ['required', 'date'],
            'ballotCloses' => ['required', 'date', 'after:ballotOpens', 'after:now'],
            'ballotCategories' => ['required', 'string', 'max:10000'],
        ], [], ['ballotTitle' => 'title', 'ballotOpens' => 'opening time', 'ballotCloses' => 'closing time', 'ballotCategories' => 'categories']);

        $categories = [];
        foreach (preg_split('/\R/', trim($this->ballotCategories)) as $line) {
            [$name, $list] = array_pad(explode(':', $line, 2), 2, '');
            $wanted = array_filter(array_map('trim', explode(',', $list)));
            $places = Place::whereIn('slug', $wanted)->orWhereIn('name', $wanted)->get();
            $missing = array_diff($wanted, $places->pluck('slug')->all(), $places->pluck('name')->all());

            if (trim($name) === '' || mb_strlen(trim($name)) > 120 || $places->count() < 2 || $missing) {
                throw ValidationException::withMessages(['ballotCategories' => $missing
                    ? 'No place called '.implode(', ', $missing).'. Use the place’s name or the slug from its address.'
                    : 'Each line needs a category name, a colon and at least two places: '.$line]);
            }
            $categories[trim($name)] = $places->pluck('id')->all();
        }

        $tz = config('stoop.timezone');
        $ballot = Ballot::start($this->ballotTitle, Carbon::parse($this->ballotOpens, $tz)->utc(), Carbon::parse($this->ballotCloses, $tz)->utc(), $categories);
        Audit::log('ballot.created', $ballot, ['title' => $ballot->title]);
        $this->reset('ballotTitle', 'ballotOpens', 'ballotCloses', 'ballotCategories');
        Flux::toast('Ballot created.');
    }

    public function closeBallot(int $id): void
    {
        $ballot = Ballot::findOrFail($id);
        $ballot->update(['closes_at' => now()]);
        Audit::log('ballot.closed', $ballot);
        Flux::toast('Closed. Results are public now.');
    }

    public function render(): View
    {
        return view('livewire.admin.editorial', [
            'threads' => EditorThread::with('editor')->latest('starts_at')->take(20)->get(),
            'ballots' => Ballot::latest('opens_at')->take(20)->get(),
        ]);
    }
}
