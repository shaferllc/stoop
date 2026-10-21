<?php

namespace Tests\Feature;

use App\Bots\Bot;
use App\Bots\Eats;
use App\Bots\History;
use App\Bots\Quizmaster;
use App\Bots\Weekend;
use App\Jobs\RunBots;
use App\Livewire\Admin\HistoryEntries;
use App\Livewire\Pages\ShowDispatch;
use App\Models\CityEvent;
use App\Models\Dispatch;
use App\Models\Place;
use App\Models\User;
use App\Support\ClaudeText;
use App\Support\DailyQuiz;
use Carbon\CarbonImmutable;
use Database\Seeders\QuizQuestionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class BotsHouseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutVite();
        config(['services.anthropic.key' => null]);
        ClaudeText::fake(null);
    }

    protected function tearDown(): void
    {
        ClaudeText::fake(null);
        parent::tearDown();
    }

    protected function travelToDc(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, config('stoop.timezone')));
    }

    /**
     * Names of this bot's scheduled jobs that are due right now.
     *
     * @return array<int, string>
     */
    protected function dueNow(Bot $bot): array
    {
        $schedule = new Schedule;
        $bot->schedule($schedule);

        return collect($schedule->events())->filter(fn ($e) => $e->isDue($this->app))->map(fn ($e) => $e->description)->values()->all();
    }

    protected function ask(string $body): ?Dispatch
    {
        $dispatch = Dispatch::factory()->create(['body' => $body]);
        (new RunBots($dispatch))->handle();

        return $dispatch->replies()->first();
    }

    protected function places(): array
    {
        return [
            'dukem' => Place::create(['name' => 'Dukem', 'category' => 'Restaurant', 'stop' => 'E03', 'blurb' => 'Ethiopian platters with injera.', 'list_name' => 'Best Ethiopian', 'list_rank' => 2, 'article_url' => 'https://example.com/dukem-review']),
            'chercher' => Place::create(['name' => 'Chercher', 'category' => 'Restaurant', 'stop' => 'E02', 'blurb' => 'Ethiopian home cooking.', 'list_name' => 'Best Ethiopian', 'list_rank' => 1]),
            'far' => Place::create(['name' => 'Far Ethiopian', 'category' => 'Restaurant', 'stop' => 'A15', 'list_name' => 'Best Ethiopian', 'list_rank' => 3]),
            'pizza' => Place::create(['name' => 'Slice Joint', 'category' => 'Restaurant', 'stop' => 'E03', 'blurb' => 'Pizza by the slice.']),
        ];
    }

    public function test_eats_answers_from_the_place_lists_near_a_station(): void
    {
        $p = $this->places();

        $reply = $this->ask('@eats cheap Ethiopian near U St');

        $this->assertSame('eats', $reply->user->username);
        $this->assertStringContainsString('1. Chercher (No. 1 · Best Ethiopian) '.route('places.show', $p['chercher']), $reply->body);
        $this->assertStringContainsString('2. Dukem (No. 2 · Best Ethiopian) '.route('places.show', $p['dukem']), $reply->body);
        $this->assertStringContainsString('Review: https://example.com/dukem-review', $reply->body);
        $this->assertStringNotContainsString('Far Ethiopian', $reply->body);
        $this->assertStringNotContainsString('Slice Joint', $reply->body);

        // One reply per dispatch, however often the job runs.
        (new RunBots($reply->parent))->handle();
        $this->assertSame(1, $reply->parent->replies()->count());
    }

    public function test_eats_says_when_nothing_matches(): void
    {
        $this->places();

        $this->assertStringContainsString('Nothing on the magazine’s lists matches that around there. Browse them all: '.route('places'), $this->ask('@eats sushi near U Street')->body);
        $this->assertStringContainsString(route('places'), $this->ask('@eats tacos near Narnia')->body);
        $this->assertSame('U Street', config('stoop.stations.'.Eats::station('u st')));
        $this->assertStringContainsString('Slice Joint', $this->ask('@eats pizza by the slice near U Street')->body);
    }

    public function test_eats_lets_claude_rank_only_when_it_is_on(): void
    {
        $p = $this->places();
        $prompts = [];
        ClaudeText::fake(function ($system, $prompt) use (&$prompts, $p) {
            $prompts[] = $prompt;

            return $p['dukem']->id.', 9999';
        });

        $reply = $this->ask('@eats injera near U Street');

        $this->assertStringContainsString('injera', $prompts[0]);
        $this->assertStringContainsString('1. Dukem', $reply->body);
        $this->assertStringNotContainsString('Chercher', $reply->body);
    }

    public function test_weekend_posts_one_round_up_on_friday_at_noon(): void
    {
        $bot = app(Weekend::class);
        $this->travelToDc('2026-10-22 12:00');
        $this->assertSame([], $this->dueNow($bot));
        $this->travelToDc('2026-10-23 11:00');
        $this->assertSame([], $this->dueNow($bot));
        $this->travelToDc('2026-10-23 12:00');
        $this->assertSame(['bot:weekend'], $this->dueNow($bot));

        // Nothing on yet: no post.
        $this->assertNull($bot->postRoundup());

        $this->travelToDc('2026-10-21 10:00');
        $saturday = Dispatch::factory()->create(['type' => 'happening', 'happening_at' => 'Saturday · 2pm', 'body' => 'Stoop clean-up on Kenyon, tickets are free @someone', 'line' => 'orange']);
        $held = Dispatch::factory()->create(['type' => 'happening', 'happening_at' => 'Saturday · 4pm', 'body' => 'A held one', 'status' => 'held']);
        $tonightWednesday = Dispatch::factory()->create(['type' => 'happening', 'happening_at' => 'Tonight · 8pm', 'body' => 'A Wednesday show']);
        Dispatch::factory()->create(['type' => 'happening', 'happening_at' => 'Half-price fries · 5pm', 'body' => 'Fries deal']);
        $picked = Dispatch::factory()->create(['type' => 'happening', 'happening_at' => 'Oct 25 · noon', 'body' => 'Fall festival at the farm', 'weekend_picked_at' => now()]);
        $this->travelToDc('2026-10-14 10:00');
        $lastWeek = Dispatch::factory()->create(['type' => 'happening', 'happening_at' => 'Saturday · 1pm', 'body' => 'Last week’s party']);
        CityEvent::create(['slug' => 'marathon', 'name' => 'Marine Corps Marathon', 'starts_at' => '2026-10-25 11:00:00', 'ends_at' => '2026-10-25 20:00:00']);
        CityEvent::create(['slug' => 'later', 'name' => 'Next Month Thing', 'starts_at' => '2026-11-20 11:00:00', 'ends_at' => '2026-11-20 20:00:00']);

        $this->travelToDc('2026-10-23 12:00');
        $post = $bot->postRoundup();

        $this->assertSame('weekend', $post->user->username);
        $this->assertStringStartsWith('Weekend round-up, Fri Oct 23 to Sun Oct 25:', $post->body);
        $this->assertStringContainsString("Editors’ picks:\n• Fall festival at the farm (Oct 25 · noon) ".route('dispatches.show', $picked), $post->body);
        $this->assertStringContainsString('Marine Corps Marathon (Sun 7:00am) '.route('events.show', 'marathon'), $post->body);
        $this->assertStringContainsString('Stoop clean-up on Kenyon, tickets are free someone (Saturday · 2pm) · Ticketed '.route('dispatches.show', $saturday), $post->body);
        foreach (['Fries deal', 'A held one', 'A Wednesday show', 'Last week’s party', 'Next Month Thing'] as $missing) {
            $this->assertStringNotContainsString($missing, $post->body);
        }
        $this->assertLessThanOrEqual(500, mb_strlen($post->body));

        $this->assertNull($bot->postRoundup());
        $this->assertSame(1, $bot->account()->dispatches()->count());
    }

    public function test_staff_pick_happening_posts_for_weekend(): void
    {
        $happening = Dispatch::factory()->create(['type' => 'happening', 'happening_at' => 'Saturday', 'parent_id' => null]);

        Livewire::actingAs(User::factory()->create())->test(ShowDispatch::class, ['dispatch' => $happening->id])
            ->call('toggleWeekendPick', $happening->id)->assertForbidden();

        $staff = User::factory()->create(['is_staff' => true]);
        Livewire::actingAs($staff)->test(ShowDispatch::class, ['dispatch' => $happening->id])
            ->assertSee('Pick for @weekend')->call('toggleWeekendPick', $happening->id);
        $this->assertNotNull($happening->fresh()->weekend_picked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'weekend.picked']);

        Livewire::actingAs($staff)->test(ShowDispatch::class, ['dispatch' => $happening->id])->call('toggleWeekendPick', $happening->id);
        $this->assertNull($happening->fresh()->weekend_picked_at);
    }

    public function test_history_posts_checked_entries_at_nine_once(): void
    {
        $bot = app(History::class);
        $this->assertSame(12, DB::table('history_entries')->where('verify', true)->count());

        $this->travelToDc('2026-03-27 09:00');
        $this->assertSame(['bot:history'], $this->dueNow($bot));

        // Seeds wait for an editor's check.
        $this->assertNull($bot->postToday());

        $staff = User::factory()->create(['is_staff' => true]);
        $metro = DB::table('history_entries')->where('text', 'like', '1976: Metrorail%')->value('id');
        Livewire::actingAs($staff)->test(HistoryEntries::class)->call('verify', $metro);

        $post = $bot->postToday();
        $this->assertStringStartsWith('On this day in DC, March 27:', $post->body);
        $this->assertStringContainsString('Metrorail opens', $post->body);
        $this->assertStringNotContainsString('cherry', $post->body);
        $this->assertSame(['red', 'A02'], [$post->line, $post->stop]);

        $this->assertNull($bot->postToday());
        $this->travelToDc('2026-03-28 09:00');
        $this->assertNull($bot->postToday());
        $this->assertSame(1, $bot->account()->dispatches()->count());
    }

    public function test_history_admin_is_staff_only_and_ai_only_fills_the_form(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/history')->assertForbidden();
        $staff = User::factory()->create(['is_staff' => true]);
        $this->actingAs($staff)->get('/admin/history')->assertOk()->assertSee('VERIFY')->assertSee('Needs ANTHROPIC_API_KEY');

        Livewire::actingAs($staff)->test(HistoryEntries::class)
            ->set('month', 2)->set('day', 30)->set('text', 'Not a real date at all.')->call('save')->assertHasErrors('day')
            ->set('day', 24)->set('text', '2020: Go-go becomes the official music of DC.')->set('stop', 'E02')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('history_entries', ['month' => 2, 'day' => 24, 'stop' => 'E02', 'verify' => false]);

        $count = DB::table('history_entries')->count();
        Livewire::actingAs($staff)->test(HistoryEntries::class)->call('draft')->assertSet('text', '');

        ClaudeText::fake(fn ($system, $prompt) => str_contains($prompt, 'August 22') ? '1934: Chuck Brown is born.' : null);
        Livewire::actingAs($staff)->test(HistoryEntries::class)->set('month', 8)->set('day', 22)->call('draft')->assertSet('text', '1934: Chuck Brown is born.');
        $this->assertSame($count, DB::table('history_entries')->count());
    }

    public function test_quizmaster_posts_the_daily_each_morning_once(): void
    {
        $this->seed(QuizQuestionSeeder::class);
        $bot = app(Quizmaster::class);

        $this->travelToDc('2026-10-21 07:00');
        $this->assertSame(['bot:quizmaster:daily'], $this->dueNow($bot));
        $post = $bot->postDaily();
        $this->assertStringStartsWith('The Daily #'.DailyQuiz::number(DailyQuiz::today()).' is up', $post->body);
        $this->assertStringContainsString(route('quiz'), $post->body);
        $this->assertNull($bot->postDaily());

        $this->travelToDc('2026-10-21 20:00');
        $this->assertSame(['bot:quizmaster:leaders'], $this->dueNow($bot));
    }

    public function test_an_early_cheer_does_not_block_the_morning_post(): void
    {
        $this->seed(QuizQuestionSeeder::class);
        $bot = app(Quizmaster::class);
        $this->travelToDc('2026-10-21 06:30');
        $player = User::factory()->create();
        $shared = Dispatch::factory()->create(['user_id' => $player->id, 'body' => DailyQuiz::shareText($this->finish($player, '2026-10-21'))]);
        (new RunBots($shared))->handle();
        $this->assertSame(1, $shared->replies()->count());

        $this->travelToDc('2026-10-21 07:00');
        $this->assertNotNull($bot->postDaily());
    }

    protected function finish(User $user, string $day, int $score = 5): object
    {
        DB::table('quiz_attempts')->insert(['user_id' => $user->id, 'day' => $day, 'question_ids' => json_encode([1, 2, 3, 4, 5]),
            'answers' => json_encode(array_map(fn ($i) => ['choice' => 0, 'correct' => $i < $score], range(0, 4))),
            'score' => $score, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return DailyQuiz::attempt($user, CarbonImmutable::parse($day));
    }

    public function test_quizmaster_posts_streak_leaders_at_eight(): void
    {
        $bot = app(Quizmaster::class);
        $this->travelToDc('2026-10-21 20:00');
        $this->assertNull($bot->postLeaders());

        $streaker = User::factory()->create(['username' => 'streaker']);
        $oneDay = User::factory()->create(['username' => 'onedayer']);
        foreach (['2026-10-19', '2026-10-20', '2026-10-21'] as $day) {
            $this->finish($streaker, $day);
        }
        $this->finish($oneDay, '2026-10-21');

        $post = $bot->postLeaders();
        $this->assertStringContainsString('1. streaker, 3 days', $post->body);
        $this->assertStringNotContainsString('onedayer', $post->body);
        $this->assertStringNotContainsString('@streaker', $post->body);
        $this->assertNull($bot->postLeaders());
    }

    public function test_quizmaster_cheers_a_shared_result_once(): void
    {
        $this->travelToDc('2026-10-21 18:00');
        $player = User::factory()->create();
        $this->finish($player, '2026-10-20');
        $attempt = $this->finish($player, '2026-10-21', 4);

        $first = Dispatch::factory()->create(['user_id' => $player->id, 'body' => DailyQuiz::shareText($attempt)]);
        (new RunBots($first))->handle();
        $reply = $first->replies()->first();
        $this->assertSame('quizmaster', $reply->user->username);
        $this->assertStringContainsString('4/5', $reply->body);
        $this->assertStringContainsString('2-day streak', $reply->body);

        // Sharing the same result again doesn't get a second cheer.
        $again = Dispatch::factory()->create(['user_id' => $player->id, 'body' => DailyQuiz::shareText($attempt)]);
        (new RunBots($again))->handle();
        $this->assertSame(0, $again->replies()->count());

        // A pasted result from someone who didn't play gets nothing.
        $faker = Dispatch::factory()->create(['body' => DailyQuiz::shareText($attempt)]);
        (new RunBots($faker))->handle();
        $this->assertSame(0, $faker->replies()->count());
    }
}
