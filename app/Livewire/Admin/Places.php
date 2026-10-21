<?php

namespace App\Livewire\Admin;

use App\Models\Place;
use App\Models\PlaceClaim;
use App\Notifications\ModerationNotice;
use App\Support\Audit;
use App\Support\Referrals;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts::stoop')]
#[Title('Places · Admin')]
class Places extends Component
{
    use StaffOnly, WithFileUploads, WithPagination;

    // Editors keep places and reservation links up to date; imports, deletes and claims stay with sales.
    protected const ROLE = ['sales', 'editor'];

    public const COLUMNS = ['name', 'category', 'neighborhood', 'stop', 'address', 'website', 'blurb', 'list_name', 'list_rank', 'article_url', 'reservation_url'];

    #[Url(except: 'places')]
    public string $tab = 'places';

    #[Url(except: '')]
    public string $q = '';

    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public $csv;

    public function edit(?int $id = null): void
    {
        $this->editing = $id ?? 0;
        $this->form = $id ? Place::findOrFail($id)->only(self::COLUMNS) : array_fill_keys(self::COLUMNS, null);
    }

    protected function rules(): array
    {
        return [
            'form.name' => ['required', 'string', 'max:120'],
            'form.category' => ['nullable', Rule::in(Place::CATEGORIES)],
            'form.neighborhood' => ['nullable', 'string', 'max:60'],
            'form.stop' => ['nullable', Rule::in(array_keys(config('stoop.stations')))],
            'form.address' => ['nullable', 'string', 'max:160'],
            'form.website' => ['nullable', 'url:http,https', 'max:300'],
            'form.blurb' => ['nullable', 'string', 'max:1000'],
            'form.list_name' => ['nullable', 'string', 'max:120'],
            'form.list_rank' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'form.article_url' => ['nullable', 'url:http,https', 'max:300'],
            'form.reservation_url' => Referrals::rules(),
        ];
    }

    public function save(): void
    {
        $data = $this->validate()['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        $place = $this->editing ? tap(Place::findOrFail($this->editing))->update($data) : Place::create($data);
        Audit::log($this->editing ? 'place.updated' : 'place.created', $place);
        $this->editing = null;
        Flux::toast('Saved.');
    }

    public function delete(int $id): void
    {
        abort_unless($this->staff()->hasRole('sales'), 403);
        $place = Place::findOrFail($id);
        Audit::log('place.deleted', $place, ['name' => $place->name]);
        $place->delete();
    }

    /**
     * Import a list from a CSV with a header row using the column names in COLUMNS.
     * Rows match existing places by name and list, so re-importing a list updates it.
     */
    public function import(): void
    {
        abort_unless($this->staff()->hasRole('sales'), 403);
        $this->validate(['csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $handle = fopen($this->csv->getRealPath(), 'r');
        // Excel's "CSV UTF-8" starts with a byte-order mark; drop it from the first column name.
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $h))), fgetcsv($handle) ?: []);

        if (! in_array('name', $header, true)) {
            $this->addError('csv', 'The first row needs column names, including “name”.');

            return;
        }

        $created = $updated = $skipped = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_intersect_key(array_combine($header, array_pad($row, count($header), null)) ?: [], array_flip(self::COLUMNS));
            $data = array_map(fn ($v) => ($v = trim((string) $v)) === '' ? null : $v, $data);
            $data['stop'] = isset($data['stop']) ? strtoupper($data['stop']) : null;

            if (! ($data['name'] ?? null) || ($data['stop'] && ! config("stoop.stations.{$data['stop']}"))) {
                $skipped++;

                continue;
            }

            $place = Place::firstOrNew(['name' => $data['name'], 'list_name' => $data['list_name'] ?? null]);
            $place->exists ? $updated++ : $created++;
            $place->fill($data)->save();
        }
        fclose($handle);

        Audit::log('places.imported', null, compact('created', 'updated', 'skipped'));
        $this->reset('csv');
        Flux::toast("Imported: $created new, $updated updated".($skipped ? ", $skipped skipped" : '').'.');
    }

    public function decideClaim(int $claimId, bool $approve): void
    {
        abort_unless($this->staff()->hasRole('sales'), 403);
        $claim = PlaceClaim::with(['place', 'user'])->where('status', 'pending')->findOrFail($claimId);
        $claim->update(['status' => $approve ? 'approved' : 'denied', 'decided_by' => $this->staff()->id]);

        if ($approve) {
            $claim->place->update(['owner_id' => $claim->user_id]);
            $claim->place->claims()->where('status', 'pending')->where('id', '!=', $claim->id)->update(['status' => 'denied']);
        }

        $claim->user->notify(new ModerationNotice($approve
            ? 'You can now post as '.$claim->place->name.'. Pick the place in the composer and check “Post as”.'
            : 'We couldn’t confirm your claim on '.$claim->place->name.'. Reply to the magazine’s team if you think that’s a mistake.'));
        Audit::log($approve ? 'claim.approved' : 'claim.denied', $claim->place, ['user' => $claim->user->username]);
    }

    public function render(): View
    {
        return view('livewire.admin.places', [
            'places' => Place::with('owner')
                ->when(trim($this->q) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($this->q).'%'))
                ->withCount('dispatches')->orderByRaw('list_name is null, list_name')->orderByRaw('list_rank is null, list_rank')->orderBy('name')
                ->paginate(50),
            'claims' => PlaceClaim::with(['place', 'user'])->where('status', 'pending')->oldest()->get(),
        ]);
    }
}
