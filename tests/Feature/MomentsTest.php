<?php

namespace Tests\Feature;

use App\Livewire\Admin\Moments;
use App\Livewire\Composer;
use App\Livewire\Pages\ContestShow;
use App\Livewire\Pages\EventShow;
use App\Livewire\Pages\Feed;
use App\Models\CityEvent;
use App\Models\Contest;
use App\Models\Dispatch;
use App\Models\Game;
use App\Models\User;
use App\Support\Achievements;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MomentsTest extends TestCase
{
    use RefreshDatabase;

    protected function staff(): User
    {
        return User::factory()->create(['is_staff' => true]);
    }

    public function test_peak_bloom_contest_ranks_the_closest_guesses(): void
    {
        $contest = Contest::create(['slug' => 'peak-bloom', 'title' => 'Peak Bloom', 'question' => 'When?', 'closes_at' => now()->addWeek(), 'winners' => 1]);
        [$close, $far] = User::factory()->count(2)->create();

        Livewire::actingAs($close)->test(ContestShow::class, ['contest' => $contest])->set('guess', now()->addDays(20)->toDateString())->call('enter');
        Livewire::actingAs($far)->test(ContestShow::class, ['contest' => $contest])->set('guess', now()->addDays(40)->toDateString())->call('enter');

        $contest->update(['answer' => now()->addDays(22)->toDateString()]);

        $this->assertSame($close->id, $contest->standings()->first()->user_id);
        Livewire::actingAs($far)->test(ContestShow::class, ['contest' => $contest])->assertSee('You finished')->assertSee('#2');
        Livewire::actingAs($far)->test(ContestShow::class, ['contest' => $contest])->set('guess', now()->addDays(22)->toDateString())->call('enter')->assertHasErrors('guess');
    }

    public function test_snow_day_mode_adds_snow_reports_and_the_map(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Composer::class)->set('type', 'snow')->set('body', 'Coming down hard')->call('file')->assertHasErrors('type');

        Livewire::actingAs($this->staff())->test(Moments::class)->call('toggleSnowDay');
        $this->assertTrue(Settings::snowDay());

        Livewire::actingAs($user)->test(Composer::class)
            ->set('type', 'snow')->set('body', 'Coming down hard')->set('stop', 'E05')->set('snowInches', '4.5')->set('plowed', 'no')
            ->call('file')->assertHasNoErrors();

        $this->get(route('snow'))->assertSee('4.5″')->assertSee('Not yet');
        $this->get('/')->assertSee('Snow Day in the DMV')->assertSee('snow-day', false);
        $this->assertArrayHasKey('snow-reporter', Achievements::unlocked($user->fresh()));
    }

    public function test_game_day_picks_score_once_the_game_is_settled(): void
    {
        $staff = $this->staff();
        Livewire::actingAs($staff)->test(Moments::class)
            ->set('game.team', 'Nationals')->set('game.opponent', 'Phillies')->set('game.starts_at', now()->addHours(3)->timezone(config('stoop.timezone'))->format('Y-m-d\TH:i'))
            ->call('createGame')->assertHasNoErrors();
        $game = Game::firstOrFail();
        $this->assertEqualsWithDelta(now()->addHours(3)->timestamp, $game->starts_at->timestamp, 60, 'Stored in UTC, not shifted by the DC-time display.');
        [$right, $wrong] = User::factory()->count(2)->create();

        Livewire::actingAs($right)->test(Feed::class)->call('vote', $game->dispatch_id, $game->team_option_id);
        Livewire::actingAs($wrong)->test(Feed::class)->call('vote', $game->dispatch_id, $game->opponent_option_id);
        $before = Achievements::xp($right);

        $this->travel(4)->hours();
        Livewire::actingAs($staff)->test(Moments::class)->call('settleGame', $game->id, 'team');

        $this->assertSame($before + 10, Achievements::xp($right->fresh()));
        $this->get(route('play'))->assertSee('Nationals won');
    }

    public function test_events_take_rated_posts_and_meetups_take_check_ins(): void
    {
        $halloween = CityEvent::create(['slug' => 'halloween', 'name' => 'Halloween', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(5), 'rating_label' => 'Candy rating']);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Composer::class)
            ->set('eventId', $halloween->id)->set('rating', 5)->set('stop', 'D06')->set('body', 'Full-size bars on 7th St SE')->call('file')->assertHasNoErrors();
        $this->assertSame(5, Dispatch::first()->details['rating']);
        Livewire::test(EventShow::class, ['event' => $halloween])->assertSee('Best rated, by stop')->assertSee('Eastern Market');

        $meetup = CityEvent::create(['slug' => 'sits', 'name' => 'Stoop Sits: Petworth', 'kind' => 'meetup', 'starts_at' => now()->subMinutes(30), 'ends_at' => now()->addHour(), 'checkin_code' => 'PUPUSA']);
        Livewire::actingAs($user)->test(EventShow::class, ['event' => $meetup])->set('code', 'nope')->call('checkIn')->assertHasErrors('code');
        Livewire::actingAs($user)->test(EventShow::class, ['event' => $meetup])->set('code', 'pupusa')->call('checkIn')->assertHasNoErrors();

        $this->assertArrayHasKey('stoop-sits', Achievements::unlocked($user->fresh()));
    }

    public function test_admin_creates_meetups_with_a_code(): void
    {
        Livewire::actingAs($this->staff())->test(Moments::class)
            ->set('event.name', 'Stoop Sits: Capitol Hill')->set('event.kind', 'meetup')
            ->set('event.starts_at', now()->addDay()->format('Y-m-d\TH:i'))->set('event.ends_at', now()->addDay()->addHours(2)->format('Y-m-d\TH:i'))
            ->call('createEvent')->assertHasNoErrors();

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', CityEvent::first()->checkin_code);
    }
}
