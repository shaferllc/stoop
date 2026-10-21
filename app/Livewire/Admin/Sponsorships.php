<?php

namespace App\Livewire\Admin;

use App\Models\Sponsorship;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Sponsorships · Admin')]
class Sponsorships extends Component
{
    use StaffOnly;

    protected const ROLE = 'sales';

    public const FIELDS = ['sponsor', 'place_id', 'stop', 'line', 'headline', 'body', 'url', 'starts_on', 'ends_on'];

    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function edit(?int $id = null): void
    {
        $this->editing = $id ?? 0;
        $this->form = $id
            ? collect(Sponsorship::findOrFail($id)->only(self::FIELDS))->map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)->all()
            : array_merge(array_fill_keys(self::FIELDS, null), ['starts_on' => now(config('stoop.timezone'))->toDateString(), 'ends_on' => now(config('stoop.timezone'))->addMonth()->toDateString()]);
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.sponsor' => ['required', 'string', 'max:120'],
            'form.place_id' => ['nullable', 'exists:places,id'],
            'form.stop' => ['nullable', 'required_without:form.line', Rule::in(array_keys(config('stoop.stations')))],
            'form.line' => ['nullable', 'required_without:form.stop', Rule::in(array_keys(config('stoop.lines')))],
            'form.headline' => ['required', 'string', 'max:120'],
            'form.body' => ['nullable', 'string', 'max:280'],
            'form.url' => ['nullable', 'url:https', 'max:300'],
            'form.starts_on' => ['required', 'date'],
            'form.ends_on' => ['required', 'date', 'after_or_equal:form.starts_on'],
        ], [], ['form.stop' => 'station', 'form.line' => 'line'])['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        $sponsorship = $this->editing ? tap(Sponsorship::findOrFail($this->editing))->update($data) : Sponsorship::create($data);
        Audit::log($this->editing ? 'sponsorship.updated' : 'sponsorship.created', $sponsorship);
        $this->editing = null;
        Flux::toast('Saved.');
    }

    public function end(int $id): void
    {
        $sponsorship = Sponsorship::findOrFail($id);
        $sponsorship->update(['ends_on' => now(config('stoop.timezone'))->subDay()->toDateString()]);
        Audit::log('sponsorship.ended', $sponsorship);
    }

    public function render(): View
    {
        return view('livewire.admin.sponsorships', ['sponsorships' => Sponsorship::with('place')->latest('starts_on')->get()]);
    }
}
