<?php

namespace Tests\Feature;

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
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

class BotsCommunityTest extends TestCase
{
    use RefreshDatabase;

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
    }

    protected function tearDown(): void
    {
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

    public function test_translate_never_translates_removed_parents_or_top_level_mentions(): void
    {
        $this->fakeClaude('nope');
        $parent = Dispatch::factory()->create(['body' => 'Something later removed']);
        $ask = Dispatch::factory()->create(['parent_id' => $parent->id, 'body' => '@translate to Korean', 'stop' => null]);
        $parent->forceFill(['status' => 'removed'])->save();
        $top = Dispatch::factory()->create(['body' => '@translate to Korean']);

        (new RunBots($ask))->handle();
        (new RunBots($top))->handle();

        // Nothing gets translated; a mention only gets the bot's how-to.
        foreach ($ask->replies->concat($top->replies) as $reply) {
            $this->assertStringContainsString('a Stoop bot', $reply->body);
        }
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
    }
}
