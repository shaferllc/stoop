<?php

namespace Tests\Feature;

use App\Bots\GameDay;
use App\Jobs\RunBots;
use App\Livewire\Composer;
use App\Livewire\Pages\Feed;
use App\Livewire\Pages\SportsTeam;
use App\Livewire\Settings\FavoriteTeams;
use App\Models\Dispatch;
use App\Models\Game;
use App\Models\User;
use App\Support\Achievements;
use App\Support\Sports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutVite();
        config(['services.anthropic.key' => null, 'services.openai.key' => null, 'services.wmata.key' => null]);
        // A Tuesday morning in DC; the Caps play at 7pm.
        $this->travelTo(Carbon::parse('2026-10-20 09:00', config('stoop.timezone')));
    }

    /**
     * An ESPN schedule event, shaped like the real feed (score as {value, displayValue}).
     *
     * @return array<string, mixed>
     */
    protected function event(string $id, string $date, string $state = 'pre', ?int $caps = null, ?int $them = null, bool $home = true): array
    {
        $done = $state === 'post';
        $side = fn (string $teamId, string $name, bool $isHome, ?int $score, bool $won) => array_filter([
            'id' => $teamId, 'homeAway' => $isHome ? 'home' : 'away', 'winner' => $won,
            'team' => ['id' => $teamId, 'displayName' => $name],
            'score' => $score === null ? null : ['value' => $score, 'displayValue' => (string) $score],
        ], fn ($v) => $v !== null);

        return [
            'id' => $id, 'date' => $date, 'timeValid' => true, 'season' => ['year' => 2027],
            'competitions' => [[
                'venue' => ['fullName' => $home ? 'Capital One Arena' : 'TD Garden'],
                'competitors' => [
                    $side('23', 'Washington Capitals', $home, $caps, $done && $caps > $them),
                    $side('1', 'Boston Bruins', ! $home, $them, $done && $them > $caps),
                ],
                'status' => ['type' => ['state' => $state, 'completed' => $done, 'shortDetail' => $done ? 'Final' : ($state === 'in' ? '2nd 10:12' : '10/20 - 7:00 PM EDT')]],
            ]],
        ];
    }

    /** @var list<array<string, mixed>> */
    protected array $capsEvents = [];

    /** Fakes ESPN: the Capitals' schedule has these events, everyone else's is empty. */
    protected function fakeEspn(array $capsEvents): void
    {
        Cache::flush();
        $this->capsEvents = $capsEvents;
        Http::fake([
            'site.api.espn.com/apis/site/v2/sports/hockey/nhl/teams/wsh/schedule*' => fn () => Http::response(['events' => $this->capsEvents]),
            'site.api.espn.com/apis/v2/sports/hockey/nhl/standings' => Http::response(['children' => [['name' => 'Eastern Conference', 'standings' => ['entries' => [
                ['team' => ['id' => '23'], 'stats' => [['name' => 'overall', 'displayValue' => '8-2-1'], ['name' => 'playoffSeed', 'displayValue' => '2']]],
            ]]]]]),
            'site.api.espn.com/*' => Http::response(['events' => []]),
        ]);
    }

    protected function tonight(string $state = 'pre', ?int $caps = null, ?int $them = null): array
    {
        return $this->event('401', '2026-10-20T23:00Z', $state, $caps, $them);
    }

    public function test_import_upserts_games_idempotently(): void
    {
        $this->fakeEspn([$this->tonight(), $this->event('402', '2026-10-24T23:00Z', home: false)]);

        $this->assertSame(2, Sports::sync(force: true));
        Cache::forget('sports:synced');
        Sports::sync();
        $this->assertNull(Sports::sync(), 'Nothing live and synced within the hour: no ESPN calls.');

        $this->assertSame(2, Game::count());
        $home = Game::where('external_id', '401')->first();
        $this->assertSame(['Capitals', 'Boston Bruins', true, 'B01', 'NHL', 2027, 'pre', 'capitals'], [$home->team, $home->opponent, $home->home, $home->stop, $home->league, $home->season, $home->status, $home->team_key]);
        $this->assertNull(Game::where('external_id', '402')->value('stop'), 'Away games have no DC stop.');
        $this->assertSame(['group' => 'Eastern Conference', 'record' => '8-2-1', 'seed' => '2'], Sports::standings('capitals'));
        // Both ESPN schedule URLs (regular season and current season type), per team.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'teams/wsh/schedule?seasontype=2'));
    }

    public function test_game_threads_open_once_and_picks_settle_from_the_final_score(): void
    {
        $this->fakeEspn([$this->tonight()]);
        $this->artisan('stoop:sync-sports', ['--force' => true])->assertSuccessful();
        $this->artisan('stoop:sync-sports')->assertSuccessful();

        $game = Game::firstOrFail();
        $thread = Dispatch::findOrFail($game->dispatch_id);
        $this->assertSame(1, Dispatch::whereNull('parent_id')->count(), 'One thread, however often it runs.');
        $this->assertSame(['gameday', 'poll', 'orange', 'B01'], [$thread->user->username, $thread->type, $thread->line, $thread->stop]);
        $this->assertTrue($thread->closes_at->equalTo($game->starts_at), 'Picks close at the start, not 24 hours later.');
        $this->assertSame($game->id, $thread->details['game']);

        [$right, $wrong] = User::factory()->count(2)->create();
        Livewire::actingAs($right)->test(Feed::class)->call('vote', $thread->id, $game->team_option_id);
        Livewire::actingAs($wrong)->test(Feed::class)->call('vote', $thread->id, $game->opponent_option_id);
        $before = Achievements::xp($right);

        // Puck drop: the five-minute run notices without waiting for the hourly import.
        $this->travelTo(Carbon::parse('2026-10-20 19:05', config('stoop.timezone')));
        $this->fakeEspn([$this->tonight('in', 2, 1)]);
        $this->assertSame(1, Sports::sync());
        $this->assertSame(['live', 2, 1, null], [$game->fresh()->status, $game->fresh()->team_score, $game->fresh()->opponent_score, $game->fresh()->winner]);
        $this->assertStringContainsString('Live: Capitals 2, Boston Bruins 1 (2nd 10:12)', (new GameDay)->reply(new Dispatch(['body' => '@gameday caps'])));

        $this->travel(3)->hours();
        $this->fakeEspn([$this->tonight('post', 4, 1)]);
        $this->artisan('stoop:sync-sports')->assertSuccessful();
        $this->artisan('stoop:sync-sports')->assertSuccessful();

        $this->assertSame(['final', 'team'], [$game->fresh()->status, $game->fresh()->winner]);
        $this->assertSame($before + 10, Achievements::xp($right->fresh()));
        $finals = $thread->replies()->get();
        $this->assertCount(1, $finals, 'The final posts once.');
        $this->assertSame('Final: Capitals 4, Boston Bruins 1. Capitals win. Right picks just earned 10 XP.', $finals->first()->body);
    }

    public function test_no_threads_before_eight_or_for_past_and_tba_games(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 07:00', config('stoop.timezone')));
        $tba = $this->event('403', '2026-10-20T20:00Z');
        $tba['timeValid'] = false;
        $this->fakeEspn([$this->tonight(), $this->event('400', '2026-10-18T23:00Z', 'post', 3, 2), $tba]);
        Sports::sync(force: true);

        $this->assertSame(0, (new GameDay)->postThreads());
        $this->travelTo(Carbon::parse('2026-10-20 08:30', config('stoop.timezone')));
        $this->assertSame(1, (new GameDay)->postThreads());
        $this->assertSame(0, (new GameDay)->postFinals(), 'Imported past games without a thread post nothing.');
    }

    public function test_draws_score_nobody(): void
    {
        $this->fakeEspn([$this->event('405', '2026-10-19T23:00Z', 'post', 2, 2)]);
        Sports::sync(force: true);

        $this->assertSame('draw', Game::first()->winner);
    }

    public function test_gameday_answers_with_the_next_game_and_the_last_result(): void
    {
        $this->fakeEspn([$this->event('400', '2026-10-18T23:00Z', 'post', 3, 2), $this->tonight()]);
        Sports::sync(force: true);
        $asker = User::factory()->create();

        $ask = Dispatch::factory()->create(['user_id' => $asker->id, 'status' => 'approved', 'body' => 'How did we do @gameday Caps']);
        (new RunBots($ask))->handle();
        $this->assertSame('Last: Capitals 3, Boston Bruins 2 (Oct 18). Next: Capitals vs. Boston Bruins, Tue Oct 20, 7:00pm at Capital One Arena.', $ask->replies()->first()->body);

        $bot = new GameDay;
        $this->assertStringStartsWith('Next: Capitals vs. Boston Bruins', $bot->reply(new Dispatch(['body' => '@gameday'])));
        $this->assertSame('No Wizards games on the schedule right now.', $bot->reply(new Dispatch(['body' => '@gameday wizards'])));
        $this->assertStringStartsWith('I don’t know that team', $bot->reply(new Dispatch(['body' => '@gameday Yankees'])));
        $this->assertSame('terps-football', Sports::findTeam('terps football tonight?'));
        $this->assertSame('terps-basketball', Sports::findTeam('Terps'));
    }

    public function test_favorite_teams_show_on_profiles_and_count_fans(): void
    {
        $fan = User::factory()->create();

        Livewire::actingAs($fan)->test(FavoriteTeams::class)->set('teams', ['capitals', 'nope'])->call('save')->assertHasErrors('teams.1');
        Livewire::actingAs($fan)->test(FavoriteTeams::class)->set('teams', ['capitals', 'spirit'])->call('save')->assertHasNoErrors();
        $this->assertSame(['capitals', 'spirit'], Sports::favorites($fan));

        $this->get(route('users.show', $fan))->assertSee('Washington Capitals')->assertSee('Washington Spirit');
        Livewire::actingAs(User::factory()->create())->test(SportsTeam::class, ['team' => 'capitals'])->call('toggleFan')->assertSee('You’re a fan');
        $this->assertSame(2, Sports::fanCounts()['capitals']);
        $this->get(route('sports.team', 'capitals'))->assertOk()->assertSee($fan->name)->assertSee('https://www.nhl.com/capitals/tickets');
        $this->get('/sports/yankees')->assertNotFound();

        $token = $fan->createToken('app', ['app'])->plainTextToken;
        $this->withToken($token)->putJson('/api/app/sports/favorites', ['teams' => ['nationals']])->assertOk()->assertJsonPath('data.teams', ['nationals']);
        $this->withToken($token)->getJson('/api/app/sports/favorites')->assertJsonPath('data.teams', ['nationals']);
        $this->withToken($token)->putJson('/api/app/sports/favorites', ['teams' => ['yankees']])->assertUnprocessable();
    }

    public function test_pickem_leaderboard_counts_right_picks_per_league_this_season(): void
    {
        $poster = User::factory()->create();
        [$ana, $ben] = User::factory()->count(2)->create();
        $bot = User::factory()->create(['is_bot' => true]);
        $game = function (string $league, int $season, string $winner) use ($poster) {
            $dispatch = Dispatch::factory()->create(['user_id' => $poster->id, 'type' => 'poll', 'status' => 'approved']);
            [$a, $b] = $dispatch->options()->createMany([['label' => 'Us'], ['label' => 'Them']]);

            return Game::create(['team' => 'Us', 'opponent' => 'Them', 'starts_at' => now(), 'league' => $league, 'season' => $season, 'winner' => $winner,
                'dispatch_id' => $dispatch->id, 'team_option_id' => $a->id, 'opponent_option_id' => $b->id]);
        };
        $vote = fn (User $u, Game $g, string $side) => DB::table('poll_votes')->insert(['dispatch_id' => $g->dispatch_id, 'user_id' => $u->id, 'poll_option_id' => $side === 'team' ? $g->team_option_id : $g->opponent_option_id]);

        [$g1, $g2] = [$game('NHL', 2027, 'team'), $game('NHL', 2027, 'opponent')];
        $old = $game('NHL', 2026, 'team');
        $nfl = $game('NFL', 2026, 'team');
        foreach ([[$ana, $g1, 'team'], [$ana, $g2, 'opponent'], [$ben, $g1, 'team'], [$ben, $g2, 'team'], [$ben, $old, 'team'], [$ben, $nfl, 'team'], [$bot, $g1, 'team']] as [$u, $g, $side]) {
            $vote($u, $g, $side);
        }

        $nhl = Sports::leaderboard('NHL');
        $this->assertSame([[$ana->username, 2], [$ben->username, 1]], $nhl->map(fn ($r) => [$r->username, (int) $r->right])->all());
        $this->assertSame(1, (int) Sports::leaderboard('NFL')->first()->right);
        $this->get(route('sports'))->assertOk()->assertSee('Pick’em leaders this season')->assertSee($ana->name);
    }

    public function test_a_happening_can_be_a_watch_party_for_a_game(): void
    {
        $this->fakeEspn([$this->tonight()]);
        Sports::sync(force: true);
        (new GameDay)->postThreads();
        $game = Game::firstOrFail();
        $host = User::factory()->create();

        Livewire::actingAs($host)->test(Composer::class)
            ->set('type', 'happening')->set('body', 'Caps watch party at the Bullpen, wear red')->set('happeningAt', 'Tonight · 6:30pm')->set('stop', 'F05')
            ->set('watchGameId', $game->id)->call('file')->assertHasNoErrors();

        $party = Dispatch::where('type', 'happening')->firstOrFail();
        $this->assertSame([$party->id], $game->watchParties()->pluck('dispatches.id')->all());
        $this->get(route('sports.game', $game))->assertOk()->assertSee('Watch parties near Metro')->assertSee('Caps watch party at the Bullpen')
            ->assertSee('Gallery Place')->assertSee('Live train times show here from three hours before the game.');
        $this->get(route('dispatches.show', $game->dispatch_id))->assertSee('Watch parties near Metro')->assertSee('Caps watch party at the Bullpen');

        // Not a happening, or the game is over: no link.
        $this->assertFalse(Sports::linkWatchParty(Dispatch::factory()->create(), $game->id));
        $game->update(['status' => 'final']);
        $this->assertFalse(Sports::linkWatchParty($party, $game->id));
    }

    public function test_rally_is_only_for_game_threads_and_the_sports_pages_render(): void
    {
        $this->fakeEspn([$this->tonight()]);
        Sports::sync(force: true);
        (new GameDay)->postThreads();
        $game = Game::firstOrFail();
        $fan = User::factory()->create();
        $token = $fan->createToken('app', ['app'])->plainTextToken;

        $this->withToken($token)->postJson("/api/app/dispatches/{$game->dispatch_id}/marks/rally")->assertOk();
        $this->assertDatabaseHas('marks', ['dispatch_id' => $game->dispatch_id, 'kind' => 'rally']);
        $other = Dispatch::factory()->create(['status' => 'approved']);
        $this->withToken($token)->postJson("/api/app/dispatches/{$other->id}/marks/rally")->assertUnprocessable();

        $this->get(route('sports'))->assertOk()->assertSee('Capitals vs. Boston Bruins')->assertSee('Make your pick')->assertSee('8-2-1');
        $this->get(route('play'))->assertOk()->assertSee('Sports');
        $this->withToken($token)->getJson('/api/app/sports/today')->assertOk()->assertJsonPath('data.today.0.opponent', 'Boston Bruins')->assertJsonPath('data.today.0.thread_id', $game->dispatch_id);
        $this->withToken($token)->getJson('/api/app/sports/teams/capitals')->assertOk()->assertJsonPath('data.upcoming.0.id', $game->id)->assertJsonPath('data.standing.record', '8-2-1');
        $this->actingAs($fan)->get(route('dispatches.show', $game->dispatch_id))->assertSee('Rally');
    }
}
