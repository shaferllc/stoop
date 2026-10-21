<?php

namespace App\Livewire\Admin;

use App\Models\CityEvent;
use App\Models\Contest;
use App\Models\Dispatch;
use App\Models\Game;
use App\Support\Audit;
use App\Support\Magazine;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Moments · Admin')]
class Moments extends Component
{
    use StaffOnly;

    protected const ROLE = 'editor';

    /** @var array<string, mixed> */
    public array $contest = ['title' => '', 'question' => '', 'prize' => '', 'closes_at' => '', 'winners' => 3];

    /** @var array<string, mixed> */
    public array $game = ['team' => 'Nationals', 'opponent' => '', 'home' => true, 'starts_at' => ''];

    /** @var array<string, mixed> */
    public array $event = ['name' => '', 'kind' => 'event', 'description' => '', 'starts_at' => '', 'ends_at' => '', 'rating_label' => '', 'place_list' => '', 'stop' => ''];

    /** @var array<int, string> */
    public array $answers = [];

    public function toggleSnowDay(): void
    {
        Settings::set('snow_day', ! Settings::snowDay());
        Audit::log(Settings::snowDay() ? 'snow_day.on' : 'snow_day.off');
        Flux::toast(Settings::snowDay() ? 'Snow Day is on.' : 'Snow Day is off.');
    }

    public function createContest(): void
    {
        $data = $this->validate([
            'contest.title' => ['required', 'string', 'max:120'],
            'contest.question' => ['required', 'string', 'max:300'],
            'contest.prize' => ['nullable', 'string', 'max:200'],
            'contest.closes_at' => ['required', 'date', $this->futureInDc()],
            'contest.winners' => ['required', 'integer', 'between:1,20'],
        ])['contest'];

        $contest = Contest::create([...$data, 'slug' => Str::slug($data['title']).'-'.now()->year, 'closes_at' => $this->dcTime($data['closes_at'])]);
        Audit::log('contest.created', $contest);
        $this->reset('contest');
        Flux::toast('Contest is up.');
    }

    public function settleContest(int $id): void
    {
        $this->validate(["answers.$id" => ['required', 'date']], [], ["answers.$id" => 'answer']);
        $contest = Contest::findOrFail($id);
        $contest->update(['answer' => $this->answers[$id]]);
        Audit::log('contest.settled', $contest, ['answer' => $this->answers[$id]]);
        Flux::toast('Results are posted.');
    }

    /**
     * A game-day thread: the magazine account posts a poll that closes at first pitch.
     */
    public function createGame(): void
    {
        $data = $this->validate([
            'game.team' => ['required', Rule::in(Game::TEAMS)],
            'game.opponent' => ['required', 'string', 'max:80'],
            'game.home' => ['boolean'],
            'game.starts_at' => ['required', 'date', $this->futureInDc()],
        ])['game'];
        $startsAt = $this->dcTime($data['starts_at']);

        $game = DB::transaction(function () use ($data, $startsAt) {
            $dispatch = Magazine::account()->dispatches()->create([
                'type' => 'poll', 'line' => 'orange', 'status' => 'approved', 'closes_at' => $startsAt,
                'body' => 'Game day: '.$data['team'].' '.($data['home'] ? 'vs.' : 'at').' '.$data['opponent'].', '.$startsAt->timezone(config('stoop.timezone'))->format('g:ia').'. Who wins? Right picks are worth 10 XP.',
            ]);
            [$team, $opponent] = $dispatch->options()->createMany([['label' => $data['team']], ['label' => $data['opponent']]]);

            return Game::create([...$data, 'starts_at' => $startsAt, 'dispatch_id' => $dispatch->id, 'team_option_id' => $team->id, 'opponent_option_id' => $opponent->id]);
        });

        Audit::log('game.created', $game);
        $this->reset('game');
        Flux::toast('Game thread posted.');
    }

    public function settleGame(int $id, string $winner): void
    {
        abort_unless(in_array($winner, ['team', 'opponent'], true), 422);
        $game = Game::findOrFail($id);
        $game->update(['winner' => $winner]);
        Dispatch::whereKey($game->dispatch_id)->update(['closes_at' => now()]);
        Audit::log('game.settled', $game, ['winner' => $winner]);
        Flux::toast('Settled. Right picks get their points.');
    }

    public function createEvent(): void
    {
        $data = $this->validate([
            'event.name' => ['required', 'string', 'max:120'],
            'event.kind' => ['required', Rule::in(['event', 'meetup'])],
            'event.description' => ['nullable', 'string', 'max:2000'],
            'event.starts_at' => ['required', 'date'],
            'event.ends_at' => ['required', 'date', 'after:event.starts_at'],
            'event.rating_label' => ['nullable', 'string', 'max:60'],
            'event.place_list' => ['nullable', 'string', 'max:120'],
            'event.stop' => ['nullable', Rule::in(array_keys(config('stoop.stations')))],
        ])['event'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        $event = CityEvent::create([
            ...$data,
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'starts_at' => $this->dcTime($data['starts_at']),
            'ends_at' => $this->dcTime($data['ends_at']),
            'checkin_code' => $data['kind'] === 'meetup' ? Str::upper(Str::random(6)) : null,
        ]);
        Audit::log('event.created', $event);
        $this->reset('event');
        Flux::toast($event->checkin_code ? 'Meetup created. Check-in code: '.$event->checkin_code : 'Event created.');
    }

    /**
     * "In the future" for a time typed in DC time (the plain after:now rule would read it as UTC).
     */
    protected function futureInDc(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (rescue(fn () => $this->dcTime((string) $value)->isPast(), true, false)) {
                $fail('Pick a time in the future.');
            }
        };
    }

    /**
     * Times typed in the admin are DC times.
     */
    protected function dcTime(string $value): CarbonImmutable
    {
        // Immutable on purpose: formatting it in DC time later must not change the stored value.
        return CarbonImmutable::parse($value, config('stoop.timezone'))->utc();
    }

    public function render(): View
    {
        return view('livewire.admin.moments', [
            'snowDay' => Settings::snowDay(),
            'contests' => Contest::withCount('entries')->latest()->take(10)->get(),
            'games' => Game::latest('starts_at')->take(15)->get(),
            'events' => CityEvent::withCount(['attendees', 'dispatches'])->latest('starts_at')->take(15)->get(),
            'lists' => DB::table('places')->whereNotNull('list_name')->distinct()->pluck('list_name'),
        ]);
    }
}
