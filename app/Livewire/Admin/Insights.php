<?php

namespace App\Livewire\Admin;

use App\Support\Audit;
use App\Support\ModerationAssist;
use App\Support\ModerationInsights;
use App\Support\WatchPhrases;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * How the OpenAI thresholds are holding up against moderators' calls, and the watch-phrase
 * list for coded and local hate.
 */
#[Layout('layouts::stoop')]
#[Title('Insights · Admin')]
class Insights extends Component
{
    use StaffOnly;

    public ?int $editing = null;

    public string $phrase = '';

    public string $meaning = '';

    public string $severity = 'medium';

    public function edit(int $id): void
    {
        $p = DB::table('watch_phrases')->find($id);
        abort_unless($p, 404);
        [$this->editing, $this->phrase, $this->meaning, $this->severity] = [$p->id, $p->phrase, $p->meaning, $p->severity];
    }

    public function save(): void
    {
        $this->validate([
            'phrase' => ['required', 'string', 'min:3', 'max:120', Rule::unique('watch_phrases')->ignore($this->editing)],
            'meaning' => ['required', 'string', 'max:500'],
            'severity' => ['required', Rule::in(WatchPhrases::SEVERITIES)],
        ]);

        $row = ['phrase' => trim($this->phrase), 'meaning' => $this->meaning, 'severity' => $this->severity, 'updated_at' => now()];
        if ($id = $this->editing) {
            DB::table('watch_phrases')->where('id', $id)->update($row);
        } else {
            $id = DB::table('watch_phrases')->insertGetId($row + ['active' => true, 'created_at' => now()]);
        }
        Audit::log('watch_phrase.saved', null, ['id' => $id, 'phrase' => $row['phrase']]);

        $this->reset('editing', 'phrase', 'meaning', 'severity');
        Flux::toast('Saved. New posts with it are held for review.');
    }

    public function toggle(int $id): void
    {
        DB::table('watch_phrases')->where('id', $id)->update(['active' => DB::raw('not active'), 'updated_at' => now()]);
        Audit::log('watch_phrase.toggled', null, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $phrase = DB::table('watch_phrases')->where('id', $id)->value('phrase');
        DB::table('watch_phrases')->delete($id);
        Audit::log('watch_phrase.deleted', null, ['id' => $id, 'phrase' => $phrase]);
    }

    public function render(): View
    {
        return view('livewire.admin.insights', [
            'thresholds' => ModerationInsights::thresholds(),
            'phrases' => DB::table('watch_phrases')->orderByDesc('active')->orderBy('phrase')->get(),
            'ai' => ModerationAssist::enabled(),
        ]);
    }
}
