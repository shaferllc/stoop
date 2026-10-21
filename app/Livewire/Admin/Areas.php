<?php

namespace App\Livewire\Admin;

use App\Models\Area;
use App\Support\Audit;
use Illuminate\Support\Facades\URL as Links;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Neighborhood areas (groups of stations) and their monthly reports for BIDs and developers:
 * printable page, CSV, and an expiring share link for clients without a Stoop login.
 */
#[Layout('layouts::stoop')]
#[Title('Area reports · Admin')]
class Areas extends Component
{
    use StaffOnly;

    public const ROLE = 'sales';

    #[Url]
    public string $month = '';

    public int $days = 14;

    /** @var array<int, string> */
    public array $links = [];

    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = ['name' => '', 'stations' => [], 'verify' => false];

    public function mount(): void
    {
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) ? $this->month : now(config('stoop.timezone'))->subMonthNoOverflow()->format('Y-m');
    }

    /**
     * A signed link to this month's report that stops working after the chosen number of days.
     */
    public function share(int $id): void
    {
        $this->validate(['days' => ['required', 'integer', 'between:1,30'], 'month' => ['required', 'date_format:Y-m']]);
        $area = Area::findOrFail($id);
        $this->links[$id] = Links::temporarySignedRoute('areas.shared', now()->addDays($this->days), ['area' => $area->id, 'month' => $this->month]);
        Audit::log('area_report.shared', null, ['area' => $area->name, 'month' => $this->month, 'days' => $this->days]);
    }

    public function edit(?int $id = null): void
    {
        $this->editing = $id ?? 0;
        $this->form = $id ? Area::findOrFail($id)->only(['name', 'stations', 'verify']) : ['name' => '', 'stations' => [], 'verify' => false];
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:80'],
            'form.stations' => ['required', 'array', 'min:1', 'max:15'],
            'form.stations.*' => [Rule::in(array_keys(config('stoop.stations')))],
            'form.verify' => ['boolean'],
        ])['form'];

        $area = $this->editing ? tap(Area::findOrFail($this->editing))->update($data) : Area::create([...$data, 'slug' => str($data['name'])->slug().'-'.str()->random(4)]);
        Audit::log($this->editing ? 'area.updated' : 'area.created', null, ['area' => $area->name]);
        $this->editing = null;
    }

    public function delete(int $id): void
    {
        $area = Area::findOrFail($id);
        Audit::log('area.deleted', null, ['area' => $area->name]);
        $area->delete();
    }

    public function render(): View
    {
        return view('livewire.admin.areas', ['areas' => Area::orderBy('name')->get()]);
    }
}
