<?php

namespace App\Livewire\Admin;

use App\Models\Hunt;
use App\Models\Place;
use App\Support\Audit;
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
 * Editors set up seasonal scavenger hunts: clues tied to a station or a place, each with an
 * optional code word players find on site.
 */
#[Layout('layouts::stoop')]
#[Title('Hunts · Admin')]
class Hunts extends Component
{
    use StaffOnly;

    public const ROLE = 'editor';

    /** @var array<string, string> */
    public array $hunt = ['name' => '', 'description' => '', 'starts_at' => '', 'ends_at' => ''];

    /** @var array<int, array<string, mixed>> */
    public array $steps = [['clue' => '', 'stop' => '', 'place_id' => '', 'code_word' => '']];

    public function addStep(): void
    {
        if (count($this->steps) < 20) {
            $this->steps[] = ['clue' => '', 'stop' => '', 'place_id' => '', 'code_word' => ''];
        }
    }

    public function removeStep(int $i): void
    {
        unset($this->steps[$i]);
        $this->steps = array_values($this->steps);
    }

    public function create(): void
    {
        $this->validate([
            'hunt.name' => ['required', 'string', 'max:120'],
            'hunt.description' => ['nullable', 'string', 'max:2000'],
            'hunt.starts_at' => ['required', 'date'],
            'hunt.ends_at' => ['required', 'date', 'after:hunt.starts_at'],
            'steps' => ['required', 'array', 'min:1', 'max:20'],
            'steps.*.clue' => ['required', 'string', 'max:300'],
            'steps.*.stop' => ['nullable', 'required_without:steps.*.place_id', Rule::in(array_keys(config('stoop.stations')))],
            'steps.*.place_id' => ['nullable', 'integer', 'exists:places,id'],
            'steps.*.code_word' => ['nullable', 'string', 'max:40'],
        ], [], ['steps.*.stop' => 'station', 'steps.*.clue' => 'clue']);

        $tz = config('stoop.timezone');
        $hunt = DB::transaction(function () use ($tz) {
            $hunt = Hunt::create([
                'name' => $this->hunt['name'],
                'description' => $this->hunt['description'] ?: null,
                'slug' => Str::slug($this->hunt['name']).'-'.Str::lower(Str::random(4)),
                'starts_at' => CarbonImmutable::parse($this->hunt['starts_at'], $tz)->utc(),
                'ends_at' => CarbonImmutable::parse($this->hunt['ends_at'], $tz)->utc(),
                'created_by' => $this->staff()->id,
            ]);
            foreach (array_values($this->steps) as $i => $step) {
                $place = $step['place_id'] ? Place::find($step['place_id']) : null;
                $hunt->steps()->create([
                    'position' => $i + 1,
                    'clue' => $step['clue'],
                    'place_id' => $place?->id,
                    'stop' => $place?->stop ?? ($step['stop'] ?: null),
                    'code_word' => $step['code_word'] ?: null,
                ]);
            }

            return $hunt;
        });

        Audit::log('hunt.created', $hunt);
        $this->reset('hunt', 'steps');
        Flux::toast('Hunt created.');
    }

    public function render(): View
    {
        return view('livewire.admin.hunts', [
            'hunts' => Hunt::with('steps.place')->latest('starts_at')->take(20)->get(),
            'places' => Place::orderBy('name')->get(['id', 'name']),
            'players' => DB::table('hunt_completions')->join('hunt_steps', 'hunt_steps.id', '=', 'hunt_completions.hunt_step_id')
                ->groupBy('hunt_steps.hunt_id')->selectRaw('hunt_steps.hunt_id, count(distinct hunt_completions.user_id) as c')->pluck('c', 'hunt_id'),
        ]);
    }
}
