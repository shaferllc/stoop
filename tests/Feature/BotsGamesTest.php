<?php

namespace Tests\Feature;

use App\Bots\BingoBot;
use App\Bots\Bot;
use App\Bots\HuntBot;
use App\Bots\LineCup;
use App\Bots\Sentinel;
use App\Jobs\RunBots;
use App\Livewire\Admin\Dashboard;
use App\Models\Dispatch;
use App\Models\Hunt;
use App\Models\Place;
use App\Models\Report;
use App\Models\SpamCluster;
use App\Models\User;
use App\Support\Bingo;
use App\Support\ClaudeText;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class BotsGamesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutVite();
        config(['services.anthropic.key' => null, 'services.openai.key' => null]);
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
     * @return array<int, string>
     */
    protected function dueNow(Bot $bot): array
    {
        $schedule = new Schedule;
        $bot->schedule($schedule);

        return collect($schedule->events())->filter(fn ($e) => $e->isDue($this->app))->map(fn ($e) => $e->description)->values()->all();
    }

    protected function ask(string $body, ?User $user = null): ?Dispatch
    {
        $dispatch = Dispatch::factory()->for($user ?? User::factory())->create(['body' => $body]);
        (new RunBots($dispatch))->handle();

        return $dispatch->replies()->first();
    }

    public function test_bingo_announces_cards_on_monday_and_the_board_on_sunday_once(): void
    {
        $bot = app(BingoBot::class);
        $this->travelToDc('2026-10-19 07:00');
        $this->assertSame([], $this->dueNow($bot));
        $this->travelToDc('2026-10-19 08:00');
        $this->assertSame(['bot:bingo:cards'], $this->dueNow($bot));
        $this->travelToDc('2026-10-25 18:00');
        $this->assertSame(['bot:bingo:board'], $this->dueNow($bot));

        $this->travelToDc('2026-10-19 08:00');
        $post = $bot->postCards();
        $this->assertStringStartsWith('New Metro Bingo cards for the week of Oct 19.', $post->body);
        $this->assertSame(3, substr_count(explode('this week: ', $post->body)[1], ';') + 1);
        $this->assertNull($bot->postCards());

        // Nobody played: no board.
        $this->travelToDc('2026-10-25 18:00');
        $this->assertNull($bot->postBoard());
    }

    public function test_bingo_tells_you_how_close_you_are_and_counts_the_bingos(): void
    {
        $this->travelToDc('2026-10-21 12:00');
        $week = Bingo::weekStart();

        // Someone whose card has a line made only of hand-marked squares (plus maybe the free one).
        for ($i = 0; $i < 300 && empty($line); $i++) {
            $user = User::factory()->create();
            $layout = Bingo::layout($user, $week);
            foreach (array_values(BingoBot::lineNames()) as $cells) {
                $keys = array_map(fn ($c) => $layout[$c], $cells);
                if (! array_filter($keys, fn ($k) => $k !== Bingo::FREE && Bingo::SQUARES[$k][1])) {
                    $line = array_values(array_filter($keys, fn ($k) => $k !== Bingo::FREE));
                    break;
                }
            }
        }
        $this->assertNotEmpty($line);

        $proof = Dispatch::factory()->for($user)->create(['stop' => null]);
        foreach (array_slice($line, 0, -1) as $square) {
            $this->assertNull(Bingo::mark($user, $square, $proof->id));
        }

        $reply = $this->ask('@bingo how am I doing?', $user);
        $this->assertSame('bingo', $reply->user->username);
        $this->assertMatchesRegularExpression('/^You’ve marked \d+ of 25; one away on (row|column|the diagonal)[^:]*: '.preg_quote(Bingo::SQUARES[end($line)][0], '/').'\./', $reply->body);
        $this->assertLessThanOrEqual(500, mb_strlen($reply->body));

        Bingo::mark($user, end($line), $proof->id);
        User::factory()->create(); // not playing
        $this->travelToDc('2026-10-25 18:00');
        $board = app(BingoBot::class)->postBoard();
        $this->assertStringContainsString('1 neighbor got a bingo, out of 1 playing.', $board->body);
        $this->assertStringNotContainsString($user->username, $board->body);
        $this->assertNull(app(BingoBot::class)->postBoard());
    }

    protected function hunt(): Hunt
    {
        $place = Place::create(['name' => 'Ben’s Chili Bowl', 'stop' => 'E03']);
        $hunt = Hunt::create(['slug' => 'fall', 'name' => 'Fall Hunt', 'starts_at' => now()->subMinutes(5), 'ends_at' => now()->addWeek()]);
        $hunt->steps()->create(['position' => 1, 'clue' => 'Where the half-smoke was born', 'place_id' => $place->id, 'code_word' => 'Mumbojumbo']);
        $hunt->steps()->create(['position' => 2, 'clue' => 'Escalators that go on forever', 'stop' => 'E05', 'code_word' => 'Longride']);

        return $hunt->load('steps');
    }

    protected function assertNoAnswers(?string $text): void
    {
        foreach (['Ben’s Chili Bowl', 'E03', 'U Street', 'E05', 'Wheaton', 'Mumbojumbo', 'Longride'] as $answer) {
            $this->assertStringNotContainsStringIgnoringCase($answer, (string) $text);
        }
    }

    public function test_hunt_posts_the_opening_clue_reminders_and_first_finisher_without_answers(): void
    {
        $bot = app(HuntBot::class);
        $this->travelToDc('2026-10-20 09:00');
        $this->assertSame([], array_diff($this->dueNow($bot), ['bot:hunt:check']));
        $this->travelToDc('2026-10-20 10:00');
        $this->assertContains('bot:hunt:reminder', $this->dueNow($bot));

        $hunt = $this->hunt();
        $this->assertSame(1, $bot->postOpenings());
        $this->assertSame(0, $bot->postOpenings());
        $opening = $bot->account()->dispatches()->firstOrFail();
        $this->assertStringStartsWith('The Fall Hunt hunt is open until Oct 27. 2 clues. The first: Where the half-smoke was born', $opening->body);
        $this->assertStringNotContainsString('Escalators', $opening->body);
        $this->assertNull($opening->stop);
        $this->assertNoAnswers($opening->body);

        // Just opened: the morning reminder waits a day, then posts once a day.
        $this->assertNull($bot->postReminder());
        $this->travelToDc('2026-10-21 10:00');
        $reminder = $bot->postReminder();
        $this->assertStringStartsWith('Still hunting? Fall Hunt runs until Oct 27. Clue 1:', $reminder->body);
        $this->assertNoAnswers($reminder->body);
        $this->assertNull($bot->postReminder());
        $this->travelToDc('2026-10-22 10:00');
        $this->assertNotNull($bot->postReminder());

        // A player asking gets their next clue, never the answer.
        $player = User::factory()->create(['username' => 'hunter1']);
        $reply = $this->ask('@hunt what’s next?', $player);
        $this->assertStringContainsString('Fall Hunt, clue 1 of 2: Where the half-smoke was born', $reply->body);
        $this->assertNoAnswers($reply->body);

        [$first, $second] = $hunt->steps;
        Dispatch::factory()->for($player)->create(['stop' => 'E03', 'type' => 'been', 'place_id' => $first->place_id]);
        $this->assertNull($first->complete($player, 'mumbojumbo'));
        $reply = $this->ask('@hunt next', $player);
        $this->assertStringContainsString('Fall Hunt, clue 2 of 2: Escalators that go on forever', $reply->body);
        $this->assertNoAnswers($reply->body);

        // Nobody's finished yet: no shout-out.
        $this->assertSame(0, $bot->postFinishers());
        Dispatch::factory()->for($player)->create(['stop' => 'E05', 'type' => 'dispatch']);
        $this->assertNull($second->complete($player, 'longride'));
        $this->assertSame(1, $bot->postFinishers());
        $this->assertSame(0, $bot->postFinishers());
        $shout = $bot->account()->dispatches()->where('body', 'like', 'First to finish%')->firstOrFail();
        $this->assertStringContainsString('Fall Hunt: hunter1, who found all 2.', $shout->body);
        $this->assertStringNotContainsString('@hunter1', $shout->body);
        $this->assertNoAnswers($shout->body);
        $this->assertStringContainsString('you found them all', $this->ask('@hunt', $player)->body);

        foreach ($bot->account()->dispatches()->get() as $post) {
            $this->assertNoAnswers($post->body);
            $this->assertNull($post->stop);
            $this->assertLessThanOrEqual(500, mb_strlen($post->body));
        }
    }

    public function test_line_cup_posts_standings_on_monday_and_the_winner_once(): void
    {
        $bot = app(LineCup::class);
        $this->travelToDc('2026-10-26 08:00');
        $this->assertSame([], $this->dueNow($bot));
        $this->travelToDc('2026-10-26 09:00');
        $this->assertSame(['bot:linecup:standings'], $this->dueNow($bot));
        $this->travelToDc('2026-11-01 10:00');
        $this->assertSame(['bot:linecup:winner'], $this->dueNow($bot));

        $this->travelToDc('2026-10-26 09:00');
        $this->assertNull($bot->postStandings());

        // Glenmont (B11) is only on the Red Line.
        foreach (range(1, 3) as $i) {
            Dispatch::factory()->create(['stop' => 'B11', 'created_at' => now()->subDays($i)]);
        }
        $this->travelToDc('2026-10-26 09:10');
        $post = $bot->postStandings();
        $this->assertStringStartsWith("Line Cup standings for October:\n1. Red Line, 3 pts", $post->body);
        $this->assertStringNotContainsString('@', $post->body);
        $this->assertNull($bot->postStandings());

        $this->travelToDc('2026-11-01 10:00');
        $winner = $bot->postWinner();
        $this->assertStringStartsWith('The October 2026 Line Cup goes to the Red Line with 3 points from 3 riders.', $winner->body);
        $this->assertNull($bot->postWinner());

        // The taunts are about lines, never people.
        foreach (LineCup::TAUNTS as $taunt) {
            $this->assertStringNotContainsString('@', $taunt);
            $this->assertStringContainsString(':', $taunt);
        }
    }

    public function test_sentinel_summarizes_the_hour_for_moderators_only_and_never_posts(): void
    {
        $bot = app(Sentinel::class);
        $this->travelToDc('2026-10-20 11:00');
        $this->assertSame(['bot:sentinel'], $this->dueNow($bot));

        $moderator = User::factory()->create(['is_staff' => true, 'roles' => ['moderator']]);
        $admin = User::factory()->create(['is_staff' => true, 'roles' => ['admin']]);
        $legacyStaff = User::factory()->create(['is_staff' => true]); // no roles means admin
        $editor = User::factory()->create(['is_staff' => true, 'roles' => ['editor']]);
        $member = User::factory()->create();

        // A quiet hour: nothing sent.
        $this->assertNull($bot->run());

        $this->travelToDc('2026-10-20 10:30');
        $held = Dispatch::factory()->create(['status' => 'held', 'body' => 'SECRET held body text', 'screening' => ['watch' => [['phrase' => 'x', 'meaning' => 'y', 'severity' => 'high']]]]);
        Dispatch::factory()->create(['status' => 'held', 'body' => 'SECRET flagged body', 'screening' => ['openai' => ['harassment' => 0.9]]]);
        Report::create(['user_id' => $member->id, 'dispatch_id' => $held->id, 'reason' => 'Spam']);
        SpamCluster::create(['kind' => 'link', 'signature' => 'l', 'summary' => 'x', 'members' => [], 'evidence' => []]);

        $this->travelToDc('2026-10-20 11:00');
        $summary = $bot->run();
        $this->assertSame('Last hour: 1 new spam cluster; 2 held posts; 1 with a watch phrase; 1 flagged by OpenAI (harassment 1); 1 open report.', $summary['text']);
        $this->assertFalse($summary['ai']);
        $this->assertNull($bot->run()); // once per hour

        // The next hour had nothing new: skipped.
        $this->travelToDc('2026-10-20 12:00');
        $this->assertNull($bot->run());

        foreach ([$moderator, $admin, $legacyStaff] as $staff) {
            $this->assertSame(1, $staff->notifications()->count());
            $this->assertStringStartsWith('@sentinel: Last hour:', $staff->notifications()->first()->data['message']);
        }
        $this->assertSame(0, $editor->notifications()->count());
        $this->assertSame(0, $member->notifications()->count());
        $this->assertSame(0, Dispatch::whereHas('user', fn ($q) => $q->where('username', 'sentinel'))->count());

        // Mentions only get the how-to, never the summary.
        $reply = $this->ask('@sentinel what happened last hour?');
        $this->assertSame($bot->help(), $reply->body);
        $this->assertStringNotContainsString('Last hour', $reply->body);

        // The API and web Overview, for moderators only.
        Sanctum::actingAs($editor, ['admin']);
        $this->getJson('/api/admin/sentinel')->assertForbidden();
        Sanctum::actingAs($moderator, ['admin']);
        $this->getJson('/api/admin/sentinel')->assertOk()->assertJsonPath('claude', false)->assertJsonPath('summaries.0.text', $summary['text']);
        Livewire::actingAs($moderator)->test(Dashboard::class)->assertSee('Sentinel')->assertSee('1 open report')->assertSee('Needs ANTHROPIC_API_KEY');
        Livewire::actingAs($editor)->test(Dashboard::class)->assertDontSee('1 open report');
    }

    public function test_sentinel_lets_claude_write_from_the_counts_only(): void
    {
        $prompts = [];
        ClaudeText::fake(function ($system, $prompt) use (&$prompts) {
            $prompts[] = $prompt;

            return 'Two held posts came in. Worth a look at the queue.';
        });
        User::factory()->create(['is_staff' => true, 'roles' => ['moderator']]);

        $this->travelToDc('2026-10-20 10:30');
        Dispatch::factory()->create(['status' => 'held', 'body' => 'SECRET held body text']);
        $this->travelToDc('2026-10-20 11:00');

        $summary = app(Sentinel::class)->run();
        $this->assertSame('Two held posts came in. Worth a look at the queue.', $summary['text']);
        $this->assertTrue($summary['ai']);
        $this->assertStringNotContainsString('SECRET', $prompts[0]);
        $this->assertSame(1, json_decode($prompts[0], true)['held']);
    }
}
