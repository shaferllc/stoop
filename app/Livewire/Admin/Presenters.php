<?php

namespace App\Livewire\Admin;

use App\Models\Presenter;
use App\Support\Audit;
use App\Support\Photos;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Sales: "Presented by" sponsors on house bots and games, on the Ads page. One per bot or game at a time.
 */
class Presenters extends Component
{
    use StaffOnly, WithFileUploads;

    protected const ROLE = 'sales';

    public const FIELDS = ['target', 'sponsor', 'url', 'starts_on', 'ends_on'];

    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var mixed */
    public $logo = null;

    public function edit(?int $id = null): void
    {
        $tz = config('stoop.timezone');
        $this->editing = $id ?? 0;
        $this->logo = null;
        $this->form = $id
            ? collect(Presenter::findOrFail($id)->only(self::FIELDS))->map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)->all()
            : ['target' => '', 'sponsor' => '', 'url' => '', 'starts_on' => now($tz)->toDateString(), 'ends_on' => now($tz)->addMonth()->toDateString()];
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.target' => ['required', Rule::in(array_keys(Presenter::targets()))],
            // No @ or links: the name goes into bot posts, where they'd turn into mentions.
            'form.sponsor' => ['required', 'string', 'max:40', 'not_regex:/[@<>]|https?:|www\./i'],
            'form.url' => ['required', 'url:https', 'max:300'],
            'form.starts_on' => ['required', 'date_format:Y-m-d'],
            'form.ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:form.starts_on'],
            'logo' => ['nullable', 'image', 'max:4096'],
        ], [], ['form.target' => 'bot or game', 'form.sponsor' => 'sponsor', 'form.url' => 'link', 'form.ends_on' => 'end'])['form'];

        $clash = Presenter::where('target', $data['target'])->whereKeyNot($this->editing ?: null)
            ->whereDate('starts_on', '<=', $data['ends_on'])->whereDate('ends_on', '>=', $data['starts_on'])->exists();
        if ($clash) {
            $this->addError('form.target', 'That already has a sponsor for some of those dates. One at a time.');

            return;
        }

        if ($this->logo) {
            $data['logo_path'] = Photos::store($this->logo, 'public', 'presenters')['path'];
        }

        $presenter = $this->editing ? tap(Presenter::findOrFail($this->editing))->update($data) : Presenter::create($data + ['created_by' => $this->staff()->id]);
        Audit::log($this->editing ? 'presenter.updated' : 'presenter.created', $presenter);
        $this->reset('editing', 'logo');
        Flux::toast('Saved.');
    }

    public function end(int $id): void
    {
        $presenter = Presenter::findOrFail($id);
        $presenter->update(['ends_on' => now(config('stoop.timezone'))->subDay()->toDateString()]);
        Audit::log('presenter.ended', $presenter);
    }

    public function render(): View
    {
        return view('livewire.admin.presenters', [
            'presenters' => Presenter::whereDate('ends_on', '>=', now(config('stoop.timezone'))->subMonth())->orderBy('starts_on')->get(),
            'targets' => $this->editing !== null ? Presenter::targets() : [],
        ]);
    }
}
