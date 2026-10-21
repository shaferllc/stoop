<?php

namespace Tests\Feature;

use App\Actions\DispatchActions;
use App\Livewire\Admin\Challenges as AdminChallenges;
use App\Livewire\Admin\MagazineEvents as AdminEvents;
use App\Livewire\Pages\ChallengeShow;
use App\Models\Challenge;
use App\Models\Dispatch;
use App\Models\Place;
use App\Models\User;
use App\Support\Achievements;
use App\Support\Challenges;
use App\Support\MagazineEvents;
use App\Support\Stations;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class MagazineEventsChallengesTest extends TestCase
{
    use RefreshDatabase;

    protected const TZ = 'America/New_York';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => null, 'services.anthropic.key' => null, 'stoop.magazine_feed' => 'https://magazine.example/feed', 'stoop.magazine_name' => 'Washingtonian']);
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 16:00', self::TZ));
    }

    protected function feed(string $seinfeldDay = 'Fri'): string
    {
        $html = '<h4><strong>October 19–October 25</strong></h4><ol>'
            ."<li><strong>Jerry Seinfeld standup.</strong> Tickets are going fast to laugh out loud <em>($seinfeldDay, \$93+, Wharf)</em>.</li>"
            .'<li><strong>Lexi Jayde.</strong> performs a live concert at Atlantis <em>(Wed, 8 pm, $35, Shaw)</em>.</li>'
            .'<li><strong>Taco crawl.</strong> Eat your way through Columbia Heights <em>(Sun, $20)</em>.</li>'
            .'<li><strong>No date here.</strong> Just a thought.</li></ol>';

        return '<?xml version="1.0"?><rss xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel>'
            .'<item><title>24 Things to Do</title><link>https://magazine.example/ttd?utm=rss</link><guid>p1</guid><pubDate>Mon, 19 Oct 2026 19:36:48 +0000</pubDate>'
            .'<category>Things to Do</category><category>Things to Do in DC</category><content:encoded><![CDATA['.$html.']]></content:encoded></item>'
            .'<item><title>Judge asks DOJ</title><link>https://magazine.example/news</link><guid>p2</guid><category>News &amp; Politics</category>'
            .'<content:encoded><![CDATA[<li><strong>Not an event.</strong> (Sat, free)</li>]]></content:encoded></item>'
            .'</channel></rss>';
    }

    public function test_import_posts_each_event_once_and_moves_changed_start_times(): void
    {
        Place::create(['name' => 'Atlantis', 'stop' => 'E03']);
        Http::fake(['magazine.example/feed' => Http::sequence()->push($this->feed())->push($this->feed())->push($this->feed('Sat')), '*' => Http::response('', 404)]);

        $this->assertSame(['posted' => 3, 'updated' => 0, 'skipped' => 0, 'error' => null], MagazineEvents::import());
        $this->assertSame(0, MagazineEvents::import()['posted'], 'Nothing posts twice.');
        $this->assertSame(3, Dispatch::where('type', 'happening')->count());

        $lexi = Dispatch::where('body', 'like', 'Lexi Jayde%')->with('happening', 'user')->firstOrFail();
        $this->assertSame('magazine', $lexi->user->username);
        $this->assertSame('E03', $lexi->stop, 'A known place beats the neighborhood.');
        $this->assertSame('orange', $lexi->line);
        $this->assertSame('2026-10-21 20:00', $lexi->happening->starts_at->timezone(self::TZ)->format('Y-m-d H:i'));
        $this->assertStringContainsString('From Washingtonian: https://magazine.example/ttd', $lexi->body);
        $this->assertStringNotContainsString('utm=', $lexi->body);

        $tacos = Dispatch::where('body', 'like', 'Taco crawl%')->firstOrFail();
        $this->assertSame(['red', 'E04'], [$tacos->line, $tacos->stop]);

        $seinfeld = Dispatch::where('body', 'like', 'Jerry Seinfeld%')->with('happening')->firstOrFail();
        $this->assertSame('Fri Oct 23', $seinfeld->happening_at, 'No made-up time when the listing has none.');

        // The listing moved to Saturday: same post, new start time.
        $this->assertSame(1, MagazineEvents::import()['updated']);
        $this->assertSame(3, Dispatch::where('type', 'happening')->count());
        $this->assertSame('2026-10-24', $seinfeld->fresh()->happening->starts_at->timezone(self::TZ)->toDateString());
        $this->assertSame('Sat Oct 24', $seinfeld->fresh()->happening_at);
        $this->assertSame(0, MagazineEvents::status()['posted']);

        // The magazine's stops never count toward mayor.
        $this->assertSame([], Stations::points(now()->subDay())->all());
    }

    public function test_long_listings_fit_and_overlapping_imports_wait(): void
    {
        $long = '<li><strong>Fall festival.</strong> '.str_repeat('Hayrides, cider and a pumpkin patch. ', 20).'<em>(Sat, free, Shaw)</em></li>';
        $feed = '<?xml version="1.0"?><rss xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><item><title>Things</title>'
            .'<link>https://magazine.example/ttd</link><guid>p9</guid><pubDate>Mon, 19 Oct 2026 19:36:48 +0000</pubDate><category>Things to Do</category>'
            .'<content:encoded><![CDATA['.$long.']]></content:encoded></item></channel></rss>';
        Http::fake(['magazine.example/feed' => Http::response($feed), '*' => Http::response('', 404)]);

        $lock = Cache::lock('stoop:magazine-events', 300);
        $lock->get();
        $this->assertSame('An import is already running.', MagazineEvents::import()['error']);
        $lock->release();

        $this->assertSame(1, MagazineEvents::import()['posted']);
        $post = Dispatch::where('type', 'happening')->firstOrFail();
        $this->assertLessThanOrEqual(500, mb_strlen($post->body));
        $this->assertSame('From Washingtonian', $post->details['magazine_event']['source']);
        $this->actingAs(User::factory()->create())->get(route('dispatches.show', $post))->assertOk()->assertSee('From Washingtonian');
    }

    public function test_station_matching(): void
    {
        Place::create(['name' => 'Busboys and Poets', 'stop' => 'E02']);

        $this->assertSame('E02', MagazineEvents::stationFor('Poetry night at Busboys and Poets', 'Takoma'));
        $this->assertSame('E02', MagazineEvents::stationFor('A concert', 'Shaw'));
        $this->assertSame('B03', MagazineEvents::stationFor('Holiday market outside Union Station', ''));
        $this->assertNull(MagazineEvents::stationFor('Air and Scare at the museum', 'Chantilly'));
    }

    public function test_editors_can_upload_a_csv_and_run_an_import(): void
    {
        $editor = User::factory()->create(['is_staff' => true, 'roles' => ['editor']]);
        $csv = "title,date,time,venue,url,description\nOyster fest,2026-10-24,1pm,Shaw,https://magazine.example/oysters,Shuck it\nBad row,not a date,,,https://x.example,\n";
        Http::fake(['magazine.example/feed' => Http::response($this->feed()), '*' => Http::response('', 404)]);

        Livewire::actingAs($editor)->test(AdminEvents::class)
            ->set('csv', UploadedFile::fake()->createWithContent('events.csv', $csv))->call('upload')->assertHasNoErrors();
        $oysters = Dispatch::where('body', 'like', 'Oyster fest%')->with('happening')->firstOrFail();
        $this->assertSame(['E02', '2026-10-24 13:00'], [$oysters->stop, $oysters->happening->starts_at->timezone(self::TZ)->format('Y-m-d H:i')]);
        $this->assertSame(1, MagazineEvents::status()['skipped']);

        Livewire::actingAs($editor)->test(AdminEvents::class)->call('importNow')->assertSee('3 posted');
    }

    protected function challenge(array $attributes = []): Challenge
    {
        return Challenge::create([
            'slug' => 'very-best', 'title' => 'Eat at 2 of the Very Best', 'list_name' => '100 Very Best', 'goal' => 2,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addWeek(), 'badge_name' => 'Very Best Eater', 'prize' => 'A tote', ...$attributes,
        ]);
    }

    /**
     * A Been here post at a place on the list, checked in at a given time.
     */
    protected function beenAt(User $user, Place $place, \DateTimeInterface $at): void
    {
        $post = Dispatch::factory()->create(['type' => 'been', 'place_id' => $place->id]);
        $this->travelTo($at);
        DispatchActions::toggleMark($user, $post, 'been');
        $this->travelBack();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 16:00', self::TZ));
    }

    public function test_progress_counts_only_check_ins_in_the_window_and_finishing_awards_once(): void
    {
        $challenge = $this->challenge();
        // Five places, so three check-ins don't also earn the list's passport stamp.
        [$a, $b, $c] = collect(['Rose’s Luxury', 'Pineapple and Pearls', 'Komi', 'Albi', 'Maydan'])->map(fn ($n) => Place::create(['name' => $n, 'stop' => 'D05', 'list_name' => '100 Very Best']));
        $elsewhere = Place::create(['name' => 'Ben’s Chili Bowl', 'stop' => 'E03', 'list_name' => 'Cheap Eats']);
        $player = User::factory()->create();

        $this->beenAt($player, $a, now()->subDays(3)); // before the start
        $this->beenAt($player, $elsewhere, now()); // another list
        $this->beenAt($player, $b, now());
        $this->assertSame(1, Challenges::progress($challenge, $player)['count']);
        $this->assertArrayNotHasKey('challenge-'.$challenge->id, Achievements::unlocked($player));

        $xp = Achievements::xp($player->fresh());
        $this->beenAt($player, $c, now()->addHour());
        $this->assertSame(2, Challenges::progress($challenge, $player)['count']);
        $this->assertDatabaseCount('challenge_finishers', 1);
        $this->assertSame('Very Best Eater', Achievements::unlocked($player->fresh())['challenge-'.$challenge->id]['name']);
        $this->assertSame($xp + Challenges::XP + 2, Achievements::xp($player->fresh()), 'Challenge XP plus the mark given.');

        // Checking again, sweeping or visiting again never awards twice.
        $this->beenAt($player, $a, now());
        $this->assertCount(0, Challenges::check($player));
        $this->assertSame(0, Challenges::sweep());
        $this->assertDatabaseCount('challenge_finishers', 1);

        Livewire::actingAs($player)->test(ChallengeShow::class, ['challenge' => $challenge])
            ->assertSee('Very Best Eater')->assertSee('@'.$player->username)->assertSee('Komi');
        Sanctum::actingAs($player, ['app']);
        $this->getJson('/api/app/challenges')->assertOk()->assertJsonPath('data.0.progress', 3);
        $this->getJson('/api/app/challenges/very-best')->assertOk()
            ->assertJsonPath('data.leaderboard.0.username', $player->username)
            ->assertJsonMissingPath('data.leaderboard.0.name')
            ->assertJsonPath('data.places.1.name', 'Komi')->assertJsonPath('data.places.1.been', true)
            ->assertJsonPath('data.places.0.been', false)
            ->assertJsonPath('data.finished_at', fn ($v) => $v !== null);
    }

    public function test_the_hourly_sweep_records_finishers_the_check_in_missed(): void
    {
        $challenge = $this->challenge(['goal' => 1]);
        $place = Place::create(['name' => 'Komi', 'stop' => 'D05', 'list_name' => '100 Very Best']);
        $player = User::factory()->create();
        $post = Dispatch::factory()->create(['type' => 'been', 'place_id' => $place->id]);
        $player->marked()->attach($post->id, ['kind' => 'been']);

        $this->assertSame(1, Challenges::sweep());
        $this->assertSame(0, Challenges::sweep());
        $this->assertNotNull(Challenges::progress($challenge, $player)['finished_at']);
    }

    public function test_only_editors_manage_challenges_and_export_finishers(): void
    {
        $challenge = $this->challenge(['goal' => 1]);
        $place = Place::create(['name' => 'Komi', 'stop' => 'D05', 'list_name' => '100 Very Best']);
        $player = User::factory()->create(['name' => '=HYPERLINK("x")', 'email' => 'winner@example.com']);
        $this->beenAt($player, $place, now());

        $moderator = User::factory()->create(['is_staff' => true, 'roles' => ['moderator']]);
        $editor = User::factory()->create(['is_staff' => true, 'roles' => ['editor']]);

        Livewire::actingAs($moderator)->test(AdminChallenges::class)->assertForbidden();
        Livewire::actingAs($moderator)->test(AdminEvents::class)->assertForbidden();
        $this->actingAs($moderator)->get(route('admin.challenges.csv', $challenge))->assertForbidden();
        $this->actingAs($player)->get(route('admin.challenges.csv', $challenge))->assertForbidden();

        Livewire::actingAs($editor)->test(AdminChallenges::class)
            ->set('form.list_name', '100 Very Best')->set('form.goal', 5)->set('form.badge_name', 'X')
            ->set('form.starts_at', '2026-11-01T00:00')->set('form.ends_at', '2026-11-30T00:00')
            ->call('create')->assertHasErrors('form.goal'); // the list only has one place
        Livewire::actingAs($editor)->test(AdminChallenges::class)
            ->set('form.list_name', '100 Very Best')->set('form.goal', 1)->set('form.badge_name', 'Regular')
            ->set('form.starts_at', '2026-11-01T00:00')->set('form.ends_at', '2026-11-30T00:00')
            ->call('create')->assertHasNoErrors()
            ->call('show', $challenge->id)->assertSee('winner@example.com');
        $this->assertSame('Visit 1 of 100 Very Best', Challenge::latest('id')->value('title'));

        $csv = $this->actingAs($editor)->get(route('admin.challenges.csv', $challenge))->assertOk()->streamedContent();
        $lines = array_map(fn ($l) => str_getcsv($l, escape: ''), array_filter(explode("\n", $csv)));
        $this->assertSame(['username', 'name', 'email', 'finished_at'], $lines[0]);
        $this->assertSame([$player->username, "'=HYPERLINK(\"x\")", 'winner@example.com'], array_slice($lines[1], 0, 3));
        $this->assertDatabaseHas('audit_logs', ['action' => 'challenge.finishers_exported']);
    }

    public function test_the_feed_shows_running_challenges(): void
    {
        $this->challenge();
        Http::fake(['*' => Http::response('', 404)]);

        $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertSee('Eat at 2 of the Very Best');
        $this->get(route('challenges'))->assertOk()->assertSee('Eat at 2 of the Very Best');
    }
}
