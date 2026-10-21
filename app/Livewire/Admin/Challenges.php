<?php

namespace App\Livewire\Admin;

use App\Models\Challenge;
use App\Support\Audit;
use App\Support\Challenges as ChallengeProgress;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Editors set up list challenges ("Eat at 10 of the 100 Very Best") and see who finished,
 * to send prizes by hand.
 */
#[Layout('layouts::stoop')]
#[Title('Challenges · Admin')]
class Challenges extends Component
{
    use StaffOnly;

    public const ROLE = 'editor';

    /** @var array<string, mixed> */
    public array $form = ['list_name' => '', 'title' => '', 'goal' => 10, 'starts_at' => '', 'ends_at' => '', 'prize' => '', 'badge_name' => ''];

    public ?int $showing = null;

    public function create(): void
    {
        $lists = DB::table('places')->whereNotNull('list_name')->groupBy('list_name')->selectRaw('list_name, count(*) as size')->pluck('size', 'list_name');

        $this->validate([
            'form.list_name' => ['required', Rule::in($lists->keys()->all())],
            'form.title' => ['nullable', 'string', 'max:160'],
            'form.goal' => ['required', 'integer', 'min:1', 'max:'.($lists[$this->form['list_name']] ?? 1)],
            'form.starts_at' => ['required', 'date'],
            'form.ends_at' => ['required', 'date', 'after:form.starts_at'],
            'form.prize' => ['nullable', 'string', 'max:500'],
            'form.badge_name' => ['required', 'string', 'max:60'],
        ], [], ['form.list_name' => 'list', 'form.goal' => 'goal', 'form.badge_name' => 'badge name', 'form.ends_at' => 'end']);

        $tz = config('stoop.timezone');
        $title = $this->form['title'] ?: 'Visit '.$this->form['goal'].' of '.$this->form['list_name'];
        $challenge = Challenge::create([
            'slug' => Str::slug(Str::limit($title, 100, '')).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'list_name' => $this->form['list_name'],
            'goal' => (int) $this->form['goal'],
            'starts_at' => CarbonImmutable::parse($this->form['starts_at'], $tz)->utc(),
            'ends_at' => CarbonImmutable::parse($this->form['ends_at'], $tz)->utc(),
            'prize' => $this->form['prize'] ?: null,
            'badge_name' => $this->form['badge_name'],
            'created_by' => $this->staff()->id,
        ]);

        Audit::log('challenge.created', $challenge);
        $this->reset('form');
        Flux::toast('Challenge created.');
    }

    public function show(int $id): void
    {
        $this->showing = $this->showing === $id ? null : $id;
    }

    public function render(): View
    {
        $open = $this->showing ? Challenge::find($this->showing) : null;

        return view('livewire.admin.challenges', [
            'lists' => DB::table('places')->whereNotNull('list_name')->groupBy('list_name')->selectRaw('list_name, count(*) as size')->orderBy('list_name')->get(),
            'challenges' => Challenge::latest('starts_at')->take(30)->get(),
            'counts' => DB::table('challenge_finishers')->groupBy('challenge_id')->selectRaw('challenge_id, count(*) as c')->pluck('c', 'challenge_id'),
            'finishers' => $open ? ChallengeProgress::finishers($open) : collect(),
        ]);
    }
}
