<?php

namespace App\Livewire\Admin;

use App\Models\Ballot;
use App\Models\BallotPromotion;
use App\Models\SponsoredDispatch;
use App\Support\Ads as AdSupport;
use App\Support\Audit;
use App\Support\Photos;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Sales: sponsored dispatches, Best of Washington party promotions, and the inventory of every
 * running and upcoming placement with delivery, clicks and CTR (and per-day reports).
 */
#[Layout('layouts::stoop')]
#[Title('Ads · Admin')]
class Ads extends Component
{
    use StaffOnly, WithFileUploads;

    protected const ROLE = 'sales';

    public const FIELDS = ['advertiser', 'body', 'url', 'stops', 'lines', 'starts_at', 'ends_at', 'daily_cap', 'goal'];

    public const PARTY_FIELDS = ['ballot_id', 'title', 'event_on', 'venue', 'url', 'price'];

    /** The ad whose daily report is open, as "kind:id". */
    #[Url(except: '')]
    public string $report = '';

    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var mixed */
    public $image = null;

    public ?int $editingParty = null;

    /** @var array<string, mixed> */
    public array $party = [];

    public function edit(?int $id = null): void
    {
        $tz = config('stoop.timezone');
        $this->editing = $id ?? 0;
        $this->image = null;
        $this->form = $id
            ? collect(SponsoredDispatch::findOrFail($id)->only(self::FIELDS))->map(fn ($v) => $v instanceof \DateTimeInterface ? $v->timezone($tz)->format('Y-m-d\TH:i') : $v)->all()
            : ['advertiser' => '', 'body' => '', 'url' => '', 'stops' => [], 'lines' => [], 'daily_cap' => 3, 'goal' => null,
                'starts_at' => now($tz)->format('Y-m-d\TH:i'), 'ends_at' => now($tz)->addMonth()->format('Y-m-d\TH:i')];
        $this->form['stops'] ??= [];
        $this->form['lines'] ??= [];
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.advertiser' => ['required', 'string', 'max:120'],
            'form.body' => ['required', 'string', 'max:500'],
            'form.url' => ['required', 'url:https', 'max:300'],
            'form.stops' => ['array', 'max:20'],
            'form.stops.*' => [Rule::in(array_keys(config('stoop.stations')))],
            'form.lines' => ['array'],
            'form.lines.*' => [Rule::in(array_keys(config('stoop.lines')))],
            'form.starts_at' => ['required', 'date'],
            'form.ends_at' => ['required', 'date', 'after:form.starts_at'],
            'form.daily_cap' => ['required', 'integer', 'between:1,50'],
            'form.goal' => ['nullable', 'integer', 'between:1,100000000'],
            'image' => ['nullable', 'image', 'max:8192'],
        ], [], ['form.advertiser' => 'advertiser', 'form.body' => 'text', 'form.url' => 'link', 'form.ends_at' => 'end', 'form.daily_cap' => 'daily cap', 'form.goal' => 'impression goal'])['form'];

        $tz = config('stoop.timezone');
        $data = [
            ...$data,
            'stops' => array_values($data['stops'] ?? []) ?: null,
            'lines' => array_values($data['lines'] ?? []) ?: null,
            'starts_at' => CarbonImmutable::parse($data['starts_at'], $tz)->utc(),
            'ends_at' => CarbonImmutable::parse($data['ends_at'], $tz)->utc(),
            'goal' => ($data['goal'] ?? '') === '' ? null : (int) $data['goal'],
        ];
        if ($this->image) {
            $data['image_path'] = Photos::store($this->image, 'public', 'ads')['path'];
        }

        $ad = $this->editing ? tap(SponsoredDispatch::findOrFail($this->editing))->update($data) : SponsoredDispatch::create($data + ['created_by' => $this->staff()->id]);
        Audit::log($this->editing ? 'ad.updated' : 'ad.created', $ad);
        $this->reset('editing', 'image');
        Flux::toast('Saved.');
    }

    public function editParty(?int $id = null): void
    {
        $this->editingParty = $id ?? 0;
        $this->party = $id
            ? collect(BallotPromotion::findOrFail($id)->only(self::PARTY_FIELDS))->map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)->all()
            : array_fill_keys(self::PARTY_FIELDS, null);
    }

    public function saveParty(): void
    {
        $data = $this->validate([
            'party.ballot_id' => ['required', 'exists:ballots,id', Rule::unique('ballot_promotions', 'ballot_id')->ignore($this->editingParty ?: null)],
            'party.title' => ['required', 'string', 'max:160'],
            'party.event_on' => ['required', 'date_format:Y-m-d'],
            'party.venue' => ['required', 'string', 'max:160'],
            'party.url' => ['required', 'url:https', 'max:300'],
            'party.price' => ['nullable', 'string', 'max:60'],
        ], ['party.ballot_id.unique' => 'That ballot already has a party.'], ['party.ballot_id' => 'ballot', 'party.event_on' => 'date', 'party.url' => 'ticket link'])['party'];

        $promo = $this->editingParty ? tap(BallotPromotion::findOrFail($this->editingParty))->update($data) : BallotPromotion::create($data);
        Audit::log($this->editingParty ? 'ad.party_updated' : 'ad.party_created', $promo);
        $this->editingParty = null;
        Flux::toast('Saved.');
    }

    /**
     * Stop a sponsored dispatch now. Sponsorships end on their own page; a party runs until its date.
     */
    public function end(string $kind, int $id): void
    {
        $ad = match ($kind) {
            'dispatch' => tap(SponsoredDispatch::findOrFail($id))->update(['ends_at' => now()]),
            default => abort(404),
        };
        Audit::log('ad.ended', $ad);
    }

    public function render(): View
    {
        [$kind, $id] = array_pad(explode(':', $this->report, 2), 2, 0);
        $reportAd = AdSupport::find($kind, (int) $id);

        return view('livewire.admin.ads', [
            'inventory' => AdSupport::inventory(),
            'ballots' => Ballot::latest('opens_at')->take(10)->get(['id', 'title']),
            'reportAd' => $reportAd,
            'reportRows' => $reportAd ? AdSupport::report($kind, (int) $id) : collect(),
            'reportReasons' => $reportAd ? DB::table('ad_hides')->where('kind', $kind)->where('ad_id', $id)->whereNotNull('reason')->groupBy('reason')->selectRaw('reason, count(*) as n')->pluck('n', 'reason') : collect(),
        ]);
    }
}
