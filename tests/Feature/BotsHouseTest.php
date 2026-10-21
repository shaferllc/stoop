<?php

namespace Tests\Feature;

<<<<<<< HEAD
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
=======
use App\Actions\FileDispatch;
use App\Bots\Ask;
use App\Bots\Bot;
use App\Bots\Calm;
use App\Bots\Recap;
use App\Bots\Translate;
use App\Bots\Welcome;
use App\Jobs\RunBots;
use App\Models\Dispatch;
use App\Models\HalfSmoke;
use App\Models\Place;
use App\Models\User;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Http\Discovery\ClassDiscovery;
use Http\Discovery\Strategy\DiscoveryStrategy;
>>>>>>> worktree-agent-a4ea324e96877cacd
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
<<<<<<< HEAD
use Livewire\Livewire;
use Tests\TestCase;

=======
use Illuminate\Support\Facades\URL;
use Psr\Http\Client\ClientInterface;
use Tests\TestCase;

/**
 * Hands the Anthropic SDK a mocked HTTP client, so Claude is faked at the wire.
 */
class FakeClaude implements DiscoveryStrategy
{
    public static ?Guzzle $client = null;

    public static function getCandidates($type)
    {
        return $type === ClientInterface::class ? [['class' => fn () => self::$client, 'condition' => true]] : [];
    }
}

>>>>>>> worktree-agent-a4ea324e96877cacd
class BotsHouseTest extends TestCase
{
    use RefreshDatabase;

<<<<<<< HEAD
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutVite();
        config(['services.anthropic.key' => null]);
        ClaudeText::fake(null);
=======
    /** @var array<int, mixed> */
    protected array $strategies;

    /** @var array<int, array<string, mixed>> */
    protected array $claudeCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Posts skip the automatic moderation check here, so a fake Claude only answers the bots.
        config(['services.anthropic.key' => null, 'services.openai.key' => null, 'stoop.model_moderation' => false]);
        Http::fake();
        Http::preventStrayRequests();
        $this->strategies = iterator_to_array(ClassDiscovery::getStrategies());
>>>>>>> worktree-agent-a4ea324e96877cacd
    }

    protected function tearDown(): void
    {
<<<<<<< HEAD
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
=======
        ClassDiscovery::setStrategies($this->strategies);
        ClassDiscovery::clearCache();
        parent::tearDown();
    }

    protected function fakeClaude(string ...$texts): void
    {
        config(['services.anthropic.key' => 'test-key']);
        $handler = HandlerStack::create(new MockHandler(array_map(fn ($text) => new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ])), $texts)));
        $handler->push(function (callable $next) {
            return function ($request, $options) use ($next) {
                $this->claudeCalls[] = json_decode((string) $request->getBody(), true);

                return $next($request, $options);
            };
        });
        FakeClaude::$client = new Guzzle(['handler' => $handler]);
        ClassDiscovery::prependStrategy(FakeClaude::class);
    }

    protected function answered(string $question, string $answer): Dispatch
    {
        $q = Dispatch::factory()->create(['type' => 'ask', 'line' => 'yellow', 'stop' => null, 'body' => $question, 'created_at' => now()->subMonth()]);
        Dispatch::factory()->create(['parent_id' => $q->id, 'body' => $answer, 'stop' => null]);

        return $q;
    }

    public function test_bots_are_found_and_schedule_themselves(): void
    {
        foreach (['ask', 'welcome', 'recap', 'translate', 'calm'] as $name) {
            $this->assertNotNull(Bot::find($name), "@$name is missing");
        }

        $schedule = new Schedule;
        foreach ([Ask::class, Welcome::class, Recap::class, Calm::class] as $class) {
            app($class)->schedule($schedule);
        }
        $crons = collect($schedule->events())->mapWithKeys(fn ($e) => [$e->description => $e->expression]);

        $this->assertSame('*/30 * * * *', $crons['bot:ask']);
        $this->assertSame('0 9 * * 0', $crons['bot:recap']);
    }

    public function test_ask_links_similar_answered_questions_once_after_two_hours(): void
    {
        $old = $this->answered('Where can I find good pupusas in Columbia Heights?', 'Try the place on 14th.');
        $this->answered('Best dentist in Navy Yard?', 'Ask your insurer.');
        $unanswered = Dispatch::factory()->create(['type' => 'ask', 'line' => 'yellow', 'stop' => null, 'body' => 'Pupusas near Columbia Heights, anyone?', 'created_at' => now()->subMonth()]);
        $question = Dispatch::factory()->create(['type' => 'ask', 'line' => 'yellow', 'stop' => null, 'body' => 'Any pupusas spots around Columbia Heights worth it?']);

        $bot = app(Ask::class);
        $this->assertSame(0, $bot->run(), 'Too soon: under two hours old.');

        $this->travel(3)->hours();
        $this->assertSame(1, $bot->run());
        $reply = $question->replies()->sole();
        $this->assertSame('ask', $reply->user->username);
        $this->assertStringContainsString(route('dispatches.show', $old), $reply->body);
        $this->assertStringNotContainsString(route('dispatches.show', $unanswered), $reply->body, 'Unanswered questions are not answers.');
        $this->assertStringContainsString('No replies yet', $reply->body);

        $this->travel(1)->hours();
        $this->assertSame(0, $bot->run());
        $this->assertSame(1, $question->replies()->count());
    }

    public function test_ask_skips_when_nothing_is_relevant_and_on_safety_or_medical_questions(): void
    {
        $this->answered('Is it safe to walk near Navy Yard at night?', 'Mostly, stick to lit streets.');
        $this->answered('Which doctor near Navy Yard takes walk-ins?', 'The clinic on M St.');
        $this->fakeClaude('Neighbors asked this before.');

        $unrelated = Dispatch::factory()->create(['type' => 'ask', 'stop' => null, 'body' => 'Where do I recycle old paint cans?', 'created_at' => now()->subHours(3)]);
        $safety = Dispatch::factory()->create(['type' => 'ask', 'stop' => null, 'body' => 'Is it safe to walk near Navy Yard at night now?', 'created_at' => now()->subHours(3)]);
        $medical = Dispatch::factory()->create(['type' => 'ask', 'stop' => null, 'body' => 'Any doctor near Navy Yard for walk-ins?', 'created_at' => now()->subHours(3)]);

        $this->assertSame(0, app(Ask::class)->run());
        $this->assertSame(0, Dispatch::whereIn('parent_id', [$unrelated->id, $safety->id, $medical->id])->count());
        $this->assertSame([], $this->claudeCalls, 'Claude is never asked about skipped questions.');
    }

    public function test_ask_uses_claude_for_one_framing_sentence(): void
    {
        $this->answered('Where to get mumbo sauce wings near Petworth?', 'The carryout on Georgia Ave.');
        $question = Dispatch::factory()->create(['type' => 'ask', 'stop' => null, 'body' => 'Mumbo sauce wings in Petworth?', 'created_at' => now()->subHours(3)]);
        $this->fakeClaude('Wing questions come up a lot, @someone. Here are earlier threads: https://evil.example');

        app(Ask::class)->run();

        $body = $question->replies()->sole()->body;
        $this->assertStringStartsWith('Wing questions come up a lot', $body);
        $this->assertStringNotContainsString('@someone', $body);
        $this->assertStringNotContainsString('evil.example', $body);
        $this->assertSame('claude-opus-5-5', $this->claudeCalls[0]['model']);
    }

    public function test_welcome_sends_one_notification_with_local_tips_and_people(): void
    {
        $regular = User::factory()->create(['username' => 'petworthpat']);
        Dispatch::factory()->for($regular)->count(3)->create(['stop' => 'E05']);
        Place::create(['name' => 'Timber Pizza', 'slug' => 'timber', 'stop' => 'E05']);
        $newbie = User::factory()->create(['stops' => ['E05']]);
        $old = User::factory()->create(['created_at' => now()->subMonth()]);

        $bot = app(Welcome::class);
        $this->assertSame(2, $bot->run(), 'Everyone who joined this week: the regular and the newbie.');
        $this->assertSame(0, $bot->run());
        $this->assertFalse($bot->welcome($newbie), 'Never twice.');

        $notice = $newbie->notifications()->sole();
        $this->assertSame('welcome', $notice->data['kind']);
        $this->assertSame('Near Petworth, try Timber Pizza.', $notice->data['tips'][0]);
        $this->assertSame('The mayor of Petworth is @petworthpat.', $notice->data['tips'][1]);
        $this->assertCount(3, $notice->data['tips']);
        $this->assertSame(['petworthpat'], $notice->data['people']);
        $this->assertSame(0, $old->notifications()->count(), 'Accounts from before this week are left alone.');
        $this->assertSame(0, Dispatch::whereHas('user', fn ($q) => $q->where('username', 'welcome'))->count(), 'No public post.');

        $this->actingAs($newbie)->get(route('notifications'))->assertOk()->assertSee('says welcome to Stoop')->assertSee('Timber Pizza');
    }

    public function test_welcome_comes_with_a_first_post_and_uses_general_tips(): void
    {
        $newbie = User::factory()->create(['stops' => null]);
        $dispatch = FileDispatch::handle($newbie, ['body' => 'My first dispatch on Stoop!'], confirmed: true);
        (new RunBots($dispatch))->handle();

        $notice = $newbie->notifications()->where('data->kind', 'welcome')->sole();
        $this->assertSame([Welcome::NO_STOPS_TIP, ...array_slice(Welcome::GENERAL_TIPS, 0, 2)], $notice->data['tips']);
        $this->assertSame(0, $dispatch->replies()->count());
        $this->assertSame(1, DB::table('bot_welcomes')->where('user_id', $newbie->id)->count());

        (new RunBots(FileDispatch::handle($newbie, ['body' => 'And a second one.'], confirmed: true)))->handle();
        $this->assertSame(1, $newbie->notifications()->where('data->kind', 'welcome')->count());
    }

    public function test_recap_posts_the_busiest_stops_without_held_or_removed_posts(): void
    {
        $this->travelTo(now()->next('Sunday')->setTime(9, 0));
        $mayor = User::factory()->create(['username' => 'dupontdee']);
        $top = Dispatch::factory()->for($mayor)->create(['stop' => 'A03', 'body' => 'Dupont farmers market is back']);
        Dispatch::factory()->for($mayor)->count(2)->create(['stop' => 'A03']);
        Dispatch::factory()->count(5)->create(['parent_id' => $top->id, 'stop' => null]);
        HalfSmoke::create(['giver_id' => User::factory()->create()->id, 'dispatch_id' => $top->id]);
        foreach (['held', 'removed'] as $status) {
            $bad = Dispatch::factory()->create(['stop' => 'A03', 'status' => $status, 'body' => "A $status post"]);
            Dispatch::factory()->count(9)->create(['parent_id' => $bad->id, 'stop' => null]);
        }
        foreach (['A01', 'B02', 'D01', 'E04', 'E05'] as $i => $stop) {
            Dispatch::factory()->count(1)->create(['stop' => $stop, 'created_at' => now()->subDays(2 + $i)]);
        }
        Dispatch::factory()->create(['stop' => 'C01', 'created_at' => now()->subDays(8)]);

        $bot = app(Recap::class);
        $this->assertSame(5, $bot->run());
        $this->assertSame(0, $bot->run(), 'Once per stop per day.');

        $recaps = $bot->account()->dispatches()->get();
        $dupont = $recaps->firstWhere('stop', 'A03');
        $this->assertStringStartsWith('Your stop this week: Dupont Circle · Mayor @dupontdee', $dupont->body);
        $this->assertStringContainsString('1. Dupont farmers market is back '.route('dispatches.show', $top), $dupont->body);
        $this->assertStringNotContainsString('held post', $dupont->body);
        $this->assertStringNotContainsString('removed post', $dupont->body);
        $this->assertNotContains('C01', $recaps->pluck('stop'), 'Older than a week.');
        $this->assertTrue($recaps->every(fn ($d) => mb_strlen($d->body) <= config('stoop.max_length')));
    }

    public function test_translate_translates_the_parent_and_needs_a_key(): void
    {
        $parent = Dispatch::factory()->create(['body' => 'Free bikes at the Petworth library today']);
        $ask = FileDispatch::handle(User::factory()->create(), ['parentId' => $parent->id, 'body' => '@translate to Spanish'], confirmed: true);

        (new RunBots($ask))->handle();
        $this->assertSame('Translation isn’t available on Stoop yet.', $ask->replies()->sole()->body);

        $this->fakeClaude('Bicicletas gratis en la biblioteca de Petworth hoy');
        $again = FileDispatch::handle(User::factory()->create(), ['parentId' => $parent->id, 'body' => '@translate into español please'], confirmed: true);
        (new RunBots($again))->handle();
        (new RunBots($again))->handle();

        $this->assertSame('Spanish: Bicicletas gratis en la biblioteca de Petworth hoy', $again->replies()->sole()->body);
        $this->assertStringContainsString('Spanish', $this->claudeCalls[0]['system']);
        $this->assertCount(1, $this->claudeCalls, 'Answered once.');

        $bot = app(Translate::class);
        foreach (['@translate to Amharic' => 'am', '@translate Vietnamese' => 'vi', '@translate to tiếng việt' => 'vi', '@translate in Chinese' => 'zh', '@translate ko' => 'ko', '@translate please' => null, '@translate this to Spanish please' => 'es', '@translate what I am asking into Korean' => 'ko', '@translate what I am saying' => null] as $body => $code) {
            $this->assertSame($code, $bot->language($body), $body);
        }
    }

    public function test_long_translations_and_link_lists_fit_in_a_dispatch(): void
    {
        $parent = Dispatch::factory()->create(['body' => str_repeat('Long post about the bike lanes. ', 15)]);
        $ask = Dispatch::factory()->create(['parent_id' => $parent->id, 'body' => '@translate to Vietnamese', 'stop' => null]);
        $this->fakeClaude(str_repeat('Bài viết dài về làn đường xe đạp. ', 20));
        (new RunBots($ask))->handle();
        $this->assertLessThanOrEqual(config('stoop.max_length'), mb_strlen($ask->replies()->sole()->body));

        URL::forceRootUrl('https://a-long-production-hostname-for-stoop.example');
        foreach (range(1, 3) as $i) {
            $this->answered("Question $i about late night halfsmoke carryouts near Shaw Howard with a long story attached to it", 'Bens.');
        }
        $question = Dispatch::factory()->create(['type' => 'ask', 'stop' => null, 'body' => 'Late night halfsmoke carryouts in Shaw?', 'created_at' => now()->subHours(3)]);
        $this->fakeClaude(str_repeat('A long framing sentence. ', 20));

        $this->assertSame(1, app(Ask::class)->run());
        $this->assertLessThanOrEqual(config('stoop.max_length'), mb_strlen($question->replies()->sole()->body));
    }

    public function test_ask_stays_quiet_when_claude_calls_a_question_sensitive(): void
    {
        $this->answered('Which dentist near Navy Yard takes new patients?', 'The one on M St.');
        $question = Dispatch::factory()->create(['type' => 'ask', 'stop' => null, 'body' => 'Any dentist near Navy Yard taking new patients?', 'created_at' => now()->subHours(3)]);
        $this->fakeClaude("SKIP.\n");

        $this->assertSame(0, app(Ask::class)->run());
        $this->assertSame(0, $question->replies()->count());
        $this->assertCount(1, $this->claudeCalls);
    }

    public function test_translate_ignores_removed_parents_and_top_level_mentions(): void
    {
        $this->fakeClaude('nope');
        $parent = Dispatch::factory()->create(['body' => 'Something later removed']);
        $ask = Dispatch::factory()->create(['parent_id' => $parent->id, 'body' => '@translate to Korean', 'stop' => null]);
        $parent->forceFill(['status' => 'removed'])->save();
        $top = Dispatch::factory()->create(['body' => '@translate to Korean']);

        (new RunBots($ask))->handle();
        (new RunBots($top))->handle();

        $this->assertSame(0, $ask->replies()->count() + $top->replies()->count());
        $this->assertSame([], $this->claudeCalls);
    }

    public function test_calm_posts_once_when_a_flagged_thread_reopens_and_never_acts(): void
    {
        config(['stoop.pile_on_threshold' => 3]);
        $thread = Dispatch::factory()->create(['body' => 'Hot take about the new bike lanes', 'stop' => null]);
        foreach (User::factory()->count(3)->create() as $stranger) {
            FileDispatch::handle($stranger, ['parentId' => $thread->id, 'body' => 'Strongly disagree with this.'], confirmed: true);
        }
        $flagged = $thread->fresh();
        $this->assertNotNull($flagged->flagged_at);

        $bot = app(Calm::class);
        $this->assertSame(0, $bot->run(), 'Replies are paused for the hour.');

        $this->travel(61)->minutes();
        $this->assertSame(1, $bot->run());
        $this->assertSame(0, $bot->run());
        $this->travel(1)->days();
        $this->assertSame(0, $bot->run());

        $this->assertContains($thread->replies()->where('user_id', $bot->account()->id)->sole()->body, Calm::LINES);
        $after = $thread->fresh();
        $this->assertEquals($flagged->flagged_at, $after->flagged_at);
        $this->assertEquals($flagged->replies_locked_until, $after->replies_locked_until);
        $this->assertSame('approved', $after->status);
        $this->assertSame(0, Dispatch::whereKeyNot($thread->id)->where('status', '!=', 'approved')->count());
>>>>>>> worktree-agent-a4ea324e96877cacd
    }
}
