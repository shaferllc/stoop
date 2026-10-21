<?php

namespace Tests\Feature;

use App\Actions\FileDispatch;
use App\Livewire\Admin\Archive as ArchivePage;
use App\Livewire\Admin\Callouts as CalloutsPage;
use App\Livewire\Admin\Issues as IssuesPage;
use App\Livewire\Admin\Radar as RadarPage;
use App\Livewire\Admin\Tips as TipsPage;
use App\Livewire\Composer;
use App\Livewire\Pages\TipCreate;
use App\Models\Dispatch;
use App\Models\Issue;
use App\Models\Message;
use App\Models\Tip;
use App\Models\User;
use App\Support\Archive;
use App\Support\ArticleUpdates;
use App\Support\Callouts;
use App\Support\Magazine;
use App\Support\Radar;
use App\Support\Tips;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class NewsroomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // No real AI checks or network calls from these tests.
        config(['services.openai.key' => null, 'services.anthropic.key' => null]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Radar::fake(null);
        parent::tearDown();
    }

    protected function staff(): User
    {
        return User::factory()->create(['is_staff' => true]);
    }

    // Tips...

    public function test_tips_are_private_to_staff_and_never_posted(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $reader = User::factory()->create();
        $about = Dispatch::factory()->create(['body' => 'The fence on 14th Street came down']);

        Livewire::actingAs($reader)->test(TipCreate::class, ['dispatch' => $about->id])
            ->set('body', 'The developer at 1400 14th Street never got a permit for this.')
            ->set('link', 'https://example.com/permit')
            ->set('photos', [UploadedFile::fake()->image('site.jpg', 400, 300)])
            ->call('submit')->assertHasNoErrors()->assertSet('sent', true);

        $tip = Tip::sole();
        $this->assertSame($about->id, $tip->dispatch_id);
        $this->assertFalse($tip->contact_ok);
        Storage::disk('local')->assertExists($tip->photos[0]);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'Tip photos never go on the public disk.');
        $this->assertSame(1, Dispatch::count(), 'A tip never becomes a dispatch.');
        $this->assertFalse(Dispatch::where('body', 'like', '%permit%')->exists());

        // Only staff see the inbox, the photos and the API.
        $this->actingAs($reader)->get(route('admin.tips'))->assertForbidden();
        $this->actingAs($reader)->get(route('admin.tips.photo', [$tip, 0]))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('search', ['q' => 'permit']))->assertDontSee('never got a permit');
        Sanctum::actingAs(User::factory()->create(), ['admin']);
        $this->getJson('/api/admin/tips')->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['is_staff' => true]), ['dispatch:write']);
        $this->getJson('/api/admin/tips')->assertForbidden();

        $staff = $this->staff();
        $this->actingAs($staff)->get(route('admin.tips'))->assertOk()->assertSee('never got a permit');
        $this->actingAs($staff)->get(route('admin.tips.photo', [$tip, 0]))->assertOk();
        Sanctum::actingAs($staff, ['admin']);
        $this->getJson('/api/admin/tips')->assertOk()->assertJsonPath('0.body', $tip->body)->assertJsonMissingPath('0.user.email');
        $this->getJson('/api/admin/attention')->assertJsonFragment(['key' => 'tips', 'count' => 1]);
    }

    public function test_tips_are_screened_and_throttled(): void
    {
        $reader = User::factory()->create();

        try {
            Tips::submit($reader, ['body' => 'This shit is going on at the corner store']);
            $this->fail('Profanity should be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
        }

        config(['services.openai.key' => 'test']);
        Http::fake(['api.openai.com/*' => Http::response(['results' => [['category_scores' => ['harassment' => 0.9]]]])]);
        $tip = Tips::submit($reader, ['body' => 'Someone keeps harassing the bus driver on the 70.']);
        $this->assertSame(['harassment' => 0.9], $tip->screening['flagged'], 'Flagged tips still arrive, with a warning for editors.');

        foreach (range(2, Tips::PER_HOUR) as $i) {
            Tips::submit($reader, ['body' => "Tip number $i about the bus line."]);
        }
        $this->expectException(ValidationException::class);
        Tips::submit($reader, ['body' => 'One tip too many this hour.']);
    }

    public function test_editors_reply_only_when_the_tipster_allowed_it(): void
    {
        $editor = $this->staff();
        $quiet = Tips::submit(User::factory()->create(), ['body' => 'Please do not contact me about this.']);
        $open = Tips::submit($tipster = User::factory()->create(), ['body' => 'Happy to talk about the pool closing.', 'contactOk' => true]);

        try {
            Tips::reply($quiet, $editor, 'Can we talk?');
            $this->fail('Contact without consent should fail.');
        } catch (ValidationException) {
        }
        $this->assertSame(0, Message::count());

        Livewire::actingAs($editor)->test(TipsPage::class)
            ->set("replies.{$open->id}", 'Thanks! Can you share when it closed?')
            ->call('reply', $open->id)
            ->call('assign', $open->id, $editor->id);

        $message = Message::sole();
        $this->assertStringContainsString('Can you share when it closed?', $message->body);
        $this->assertTrue($message->conversation->participants->contains($tipster));
        $this->assertSame('following', $open->fresh()->status);
        $this->assertSame($editor->id, $open->fresh()->assigned_to);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tip.replied']);
    }

    // Story radar...

    public function test_radar_math_needs_volume_and_a_baseline(): void
    {
        $this->assertSame(100, Radar::change(10, 5.0));
        $this->assertSame(-50, Radar::change(5, 10.0));
        $this->assertNull(Radar::change(9, 1.0), 'Tiny baselines show as new, not +800%.');
        $this->assertNull(Radar::trend('Tiny', 4, 0), 'Too little this week to show.');
        $this->assertNull(Radar::trend('Flat', 10, 40), 'Not growing.');
        $this->assertSame(150, Radar::trend('Hot', 10, 16)['change']);
        $this->assertNull(Radar::trend('Brand new', 6, 2)['change']);
        $this->assertSame(['pool', 'closing', 'petworth'], Radar::terms('The POOL is closing?? https://x.com/pool @neighbor Petworth pool'));
    }

    public function test_radar_finds_growing_stations_words_and_clusters(): void
    {
        $person = User::factory()->create();
        $bot = User::factory()->create(['is_bot' => true]);
        $post = fn (array $attrs, $when) => Dispatch::factory()->create(['user_id' => $person->id, 'status' => 'approved', 'created_at' => $when, ...$attrs]);

        // Shaw: 1 a week before, 6 this week (4 posts, 2 replies counted toward the station).
        foreach (range(1, 4) as $i) {
            $post(['stop' => 'E02', 'line' => 'red', 'body' => "Shaw pool closing early again $i"], now()->subWeeks($i)->subDays(1));
        }
        $thread = $post(['stop' => 'E02', 'line' => 'red', 'body' => 'Shaw pool is closing'], now()->subDay());
        foreach (range(1, 3) as $i) {
            $post(['stop' => 'E02', 'line' => 'red', 'body' => "Pool closing at Shaw $i"], now()->subDays(2));
        }
        $post(['parent_id' => $thread->id, 'stop' => null, 'line' => 'red', 'body' => 'The pool closing is a shame'], now()->subHours(3));
        $post(['parent_id' => $thread->id, 'stop' => null, 'line' => 'red', 'body' => 'Pool closing, again'], now()->subHours(2));
        // Bots don't count.
        Dispatch::factory()->count(10)->create(['user_id' => $bot->id, 'stop' => 'A01', 'status' => 'approved', 'body' => 'Train times']);
        // Five Ask DC questions about the same place.
        foreach (range(1, 5) as $i) {
            $post(['type' => 'ask', 'stop' => null, 'line' => 'yellow', 'body' => "Has anyone been to Call Your Mother in Petworth yet? $i"], now()->subDays(3));
        }

        $radar = Radar::build();
        $shaw = collect($radar['stations'])->firstWhere('key', 'stop:E02');
        $this->assertEquals(['current' => 6, 'posts' => 4, 'replies' => 2, 'change' => null], array_intersect_key($shaw, array_flip(['current', 'posts', 'replies', 'change'])));
        $this->assertNull(collect($radar['stations'])->firstWhere('key', 'stop:A01'));
        $this->assertNotNull(collect($radar['keywords'])->firstWhere('label', 'pool'));
        $cluster = collect($radar['clusters'])->firstWhere('label', 'Call Your Mother');
        $this->assertSame(5, $cluster['current']);
        $this->assertNotNull(collect($radar['clusters'])->firstWhere('label', 'Petworth'));

        // Story ideas: off without a key, fake Claude when on.
        $page = Livewire::actingAs($this->staff())->test(RadarPage::class)->assertSee('Needs ANTHROPIC_API_KEY')->assertDontSeeHtml('wire:click="idea(');
        Radar::fake(fn (string $prompt) => str_contains($prompt, 'Shaw') ? 'Look into the Shaw pool hours.' : 'Other');
        $page->call('refresh')->call('idea', 'stop:E02')->assertSet('ideas.stop:E02', 'Look into the Shaw pool hours.');

        Sanctum::actingAs($this->staff(), ['admin']);
        $this->getJson('/api/admin/radar')->assertOk()->assertJsonPath('ideas_enabled', true);
    }

    // Callouts...

    public function test_only_consented_callout_replies_can_be_quoted_or_exported(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $staff = $this->staff()->forceFill(['accepted_rules_at' => now()]);
        $callout = FileDispatch::handle($staff, ['type' => 'callout', 'body' => 'Tell us about your landlord'], confirmed: true);
        $yes = User::factory()->create(['username' => 'willing', 'accepted_rules_at' => now()]);
        $no = User::factory()->create(['accepted_rules_at' => now()]);

        Livewire::actingAs($yes)->test(Composer::class, ['parentId' => $callout->id])
            ->assertSee('The newsroom may quote this reply in a story.')
            ->set('body', '=HYPERLINK("x") My landlord fixed nothing for a year')->set('quoteOk', true)->call('file')->assertHasNoErrors();
        Livewire::actingAs($no)->test(Composer::class, ['parentId' => $callout->id])
            ->set('body', 'Do not quote me on this one')->call('file');

        // Consent only sticks on callout replies.
        $other = Dispatch::factory()->create();
        $reply = FileDispatch::handle($yes, ['body' => 'Just a reply', 'parentId' => $other->id, 'quoteOk' => true], confirmed: true);
        $this->assertNull($reply->details);

        $quotes = Callouts::quotes($callout);
        $this->assertSame(['willing'], $quotes->pluck('user.username')->all());
        $csv = Callouts::csv($callout);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'Formula cells are neutralized.');
        $this->assertStringNotContainsString('Do not quote me', $csv);

        Livewire::actingAs($staff)->test(CalloutsPage::class)->call('select', $callout->id)
            ->set('storyUrl', 'https://magazine.example/landlords')->set('storyTitle', 'Bad landlords')->call('saveStory')
            ->assertSee('My landlord fixed nothing')->assertDontSee('Do not quote me')
            ->call('export')->assertFileDownloaded('callout-'.$callout->id.'-quotes.csv');
        $this->assertSame('Bad landlords', $callout->fresh()->details['story']['title']);

        Sanctum::actingAs($staff, ['admin']);
        $this->getJson("/api/admin/callouts/{$callout->id}/quotes")->assertJsonCount(1)->assertJsonPath('0.user.username', 'willing');
    }

    // Article updates...

    public function test_article_updates_notify_everyone_who_joined_in_once(): void
    {
        config(['stoop.magazine_feed' => 'https://magazine.example/feed']);
        $feed = fn (string $summary) => ('<?xml version="1.0"?><rss xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel>
            <item><title>The best pizza near Navy Yard</title><link>https://magazine.example/pizza</link><guid>p1</guid><category>Food</category>
            <description>'.$summary.'</description><content:encoded>Full text</content:encoded></item></channel></rss>');
        Http::fake([
            'magazine.example/feed' => Http::sequence()->push($feed('Ten slices.'))->push($feed('Ten slices.'))->push($feed('Eleven slices, corrected.'))->push($feed('Eleven slices, corrected.')),
            '*' => Http::response('', 404),
        ]);

        $this->assertSame(1, Magazine::import());
        $article = DB::table('articles')->sole();
        $thread = Dispatch::findOrFail($article->dispatch_id);
        $this->assertNull($thread->stop, '@magazine never takes a stop, so it can’t earn mayor points.');
        $this->assertSame('F05', $article->stations);

        $busy = User::factory()->create(['accepted_rules_at' => now()]);
        $saver = User::factory()->create();
        $muter = User::factory()->create();
        foreach ([1, 2] as $i) {
            FileDispatch::handle($busy, ['body' => "Reply number $i", 'parentId' => $thread->id], confirmed: true);
        }
        DB::table('marks')->insert([
            ['user_id' => $busy->id, 'dispatch_id' => $thread->id, 'kind' => 'save', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $saver->id, 'dispatch_id' => $thread->id, 'kind' => 'save', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $muter->id, 'dispatch_id' => $thread->id, 'kind' => 'mumbo', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('half_smokes')->insert(['giver_id' => $busy->id, 'dispatch_id' => $thread->id]);
        DB::table('silences')->insert(['user_id' => $muter->id, 'target_id' => Magazine::account()->id, 'type' => 'mute', 'created_at' => now(), 'updated_at' => now()]);

        Magazine::import();
        $this->assertSame(0, DB::table('article_updates')->count(), 'Nothing changed, nothing posted.');

        Magazine::import();
        Magazine::import();
        $this->assertSame(1, DB::table('article_updates')->count(), 'One update, posted once.');
        $this->assertSame(1, $busy->notifications()->where('data->kind', 'update')->count());
        $this->assertSame(1, $saver->notifications()->where('data->kind', 'update')->count());
        $this->assertSame(0, $muter->notifications()->where('data->kind', 'update')->count());
        $this->assertTrue($thread->replies()->where('user_id', Magazine::account()->id)->where('body', 'like', 'Update:%')->exists());

        // An editor's correction is its own update, and repeating its key does nothing.
        $editor = $this->staff();
        Livewire::actingAs($editor)->test(IssuesPage::class)->set("notes.{$article->id}", 'Corrected the address of the second shop.')->call('postUpdate', $article->id);
        $this->assertSame(2, $saver->notifications()->where('data->kind', 'update')->count());
        $this->assertNull(ArticleUpdates::post($article, 'Again', DB::table('article_updates')->latest('id')->value('key')));
        $this->actingAs($saver)->get(route('notifications'))->assertSee('posted an update to an article you joined in on');
    }

    public function test_articles_imported_before_hashes_dont_announce_an_update(): void
    {
        config(['stoop.magazine_feed' => 'https://magazine.example/feed']);
        Http::fake(['magazine.example/feed' => Http::response('<?xml version="1.0"?><rss><channel><item><title>Old one</title><link>https://magazine.example/old</link><guid>o1</guid></item></channel></rss>'), '*' => Http::response('', 404)]);
        $thread = Dispatch::factory()->create(['user_id' => Magazine::account()->id]);
        DB::table('articles')->insert(['guid_hash' => hash('sha256', 'o1'), 'url' => 'https://magazine.example/old', 'title' => 'Old one', 'dispatch_id' => $thread->id, 'created_at' => now(), 'updated_at' => now()]);

        Magazine::import();
        $this->assertNotNull(DB::table('articles')->value('content_hash'));
        $this->assertSame(0, DB::table('article_updates')->count());
    }

    // The archive...

    public function test_the_archive_tagger(): void
    {
        $tag = fn (string $text) => Archive::tag($text);

        $this->assertSame(['E02'], $tag('The best new bars in Shaw')['stations']);
        $this->assertSame([], $tag('Shawn Mendes plays Capital One Arena')['stations'], 'Whole words only.');
        $this->assertSame([], $tag('Inside the National archives')['stations'], 'Lowercase and generic names don’t tag.');
        $this->assertSame([], $tag('Inside the National Archives')['stations'], 'Archives is too generic to tag on its own.');
        $this->assertSame(['D03'], $tag('Lunch near L\'Enfant Plaza')['stations'], 'Straight and curly apostrophes match.');
        $this->assertSame(['E04'], $tag('Pupusas in Columbia Heights')['stations'], 'Aliases spell out abbreviated station names.');
        $result = $tag('A walk from Adams Morgan to Dupont Circle');
        $this->assertEqualsCanonicalizing(['A04', 'A03'], $result['stations']);
        $this->assertSame(['Adams Morgan'], $result['neighborhoods']);
    }

    public function test_archive_import_and_station_pages(): void
    {
        $csv = "\xEF\xBB\xBFtitle,url,date,section,stations,neighborhoods,description\n"
            ."The Fight Over the Navy Yard Waterfront,https://magazine.example/a,2019-05-01,News,,,\n"
            ."Where to Eat Now,https://magazine.example/b,2020-01-01,Food,e02,,\n"
            .",https://magazine.example/c,,,,,\n"
            ."Bad link,javascript:alert(1),,,,,\n";
        $file = UploadedFile::fake()->createWithContent('archive.csv', $csv);
        $staff = $this->staff();

        Livewire::actingAs($staff)->test(ArchivePage::class)->set('csv', $file)->call('import')->assertHasNoErrors();
        $this->assertSame(2, DB::table('archive_stories')->count());
        $this->assertSame(['F05'], DB::table('archive_story_stations')->where('archive_story_id', DB::table('archive_stories')->where('url', 'https://magazine.example/a')->value('id'))->pluck('stop')->all());
        $this->assertSame(1, DB::table('archive_story_stations')->where('stop', 'E02')->count(), 'Explicit codes win.');

        $this->get(route('home', ['stop' => 'F05']))->assertSee('From the archive')->assertSee('The Fight Over the Navy Yard Waterfront');
        $this->get(route('home', ['stop' => 'A01']))->assertDontSee('From the archive');

        // A new alias, then retagging, picks up more stories.
        DB::table('archive_stories')->insert(['url' => 'https://magazine.example/d', 'title' => 'Dumplings on Barracks Row', 'created_at' => now(), 'updated_at' => now()]);
        Livewire::actingAs($staff)->test(ArchivePage::class)->set('alias', 'Dumpling Alley')->set('aliasStop', 'D06')->call('addAlias')->call('retag');
        $this->assertSame(1, DB::table('archive_story_stations')->where('stop', 'D06')->count());
        $this->assertTrue(DB::table('station_aliases')->where('alias', 'Dumpling Alley')->exists());
        $this->assertDatabaseHas('audit_logs', ['action' => 'archive.imported']);
    }

    // Issues...

    public function test_publishing_an_issue_posts_one_thread_once(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        DB::table('articles')->insert(['guid_hash' => 'x', 'url' => 'https://magazine.example/s1', 'title' => 'Story One', 'created_at' => now(), 'updated_at' => now()]);
        $urls = collect(range(1, 12))->map(fn ($i) => "https://magazine.example/s$i")->push('javascript:alert(1)')->join("\n");

        Livewire::actingAs($staff = $this->staff())->test(IssuesPage::class)
            ->set('name', 'November 2026')->set('coverUrl', 'https://magazine.example/cover.jpg')->set('urls', $urls)
            ->call('create')->assertHasNoErrors();
        $issue = Issue::sole();
        $this->assertCount(12, $issue->stories, 'Only http(s) links.');
        $this->assertSame('Story One', $issue->stories[0]['title']);
        auth()->logout();
        $this->get(route('issues.show', $issue))->assertNotFound();

        Livewire::actingAs($staff)->test(IssuesPage::class)->call('publish', $issue->id)->call('publish', $issue->id);
        $issue->refresh();
        $thread = $issue->dispatch;
        $this->assertSame('magazine', $thread->user->username);
        $this->assertSame(12, $thread->replies()->count(), 'Every story posts, past the 10-a-minute limit.');
        $this->assertSame(1, Dispatch::whereNull('parent_id')->count(), 'Publishing twice posts once.');
        $this->assertStringContainsString(route('issues.show', $issue), $thread->body);

        $this->get(route('issues.show', $issue))->assertOk()->assertSee('The November 2026 issue')->assertSee('Story One');
    }

    public function test_newsroom_admin_pages_are_staff_only(): void
    {
        $reader = User::factory()->create();
        foreach (['admin.tips', 'admin.radar', 'admin.callouts', 'admin.archive', 'admin.issues'] as $route) {
            $this->actingAs($reader)->get(route($route))->assertForbidden();
            $this->actingAs($this->staff())->get(route($route))->assertOk();
        }
        $this->actingAs($reader)->get(route('tips.create'))->assertOk()->assertSee('Tip the newsroom');
    }
}
