<?php

namespace App\Livewire\Admin;

use App\Support\BotConsole;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Bots · Admin')]
class Bots extends Component
{
    use StaffOnly;

    protected const ROLE = 'editor';

    /** The answer being corrected, and the correction. */
    public ?int $correcting = null;

    public string $correction = '';

    /** The knowledge-base entry being edited (null for a new one). */
    public ?int $editing = null;

    public string $section = '';

    public string $body = '';

    public function markWrong(int $id): void
    {
        BotConsole::markWrong(BotConsole::answer($id));
    }

    public function correct(): void
    {
        $this->validate(['correction' => ['required', 'string', 'min:3', 'max:'.config('stoop.max_length')]]);
        BotConsole::correct(BotConsole::answer((int) $this->correcting), $this->correction);
        $this->reset('correcting', 'correction');
        Flux::toast('Correction posted.');
    }

    public function edit(int $id): void
    {
        $entry = DB::table('bot_knowledge')->find($id);
        [$this->editing, $this->section, $this->body] = [$entry->id, $entry->section, $entry->body];
    }

    public function save(): void
    {
        $this->validate(BotConsole::entryRules());
        BotConsole::saveEntry($this->editing, $this->section, $this->body);
        $this->reset('editing', 'section', 'body');
        Flux::toast('Saved.');
    }

    public function delete(int $id): void
    {
        BotConsole::deleteEntry($id);
    }

    public function render(): View
    {
        return view('livewire.admin.bots', ['answers' => BotConsole::answers(), 'entries' => BotConsole::knowledge()->groupBy('section')]);
    }
}
