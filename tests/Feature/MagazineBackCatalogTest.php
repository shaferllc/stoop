<?php

namespace Tests\Feature;

use App\Livewire\Admin\Archive as ArchiveAdmin;
use App\Livewire\Pages\Search;
use App\Models\Author;
use App\Models\Dispatch;
use App\Models\Place;
use App\Models\User;
use App\Support\MagazineBackCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

class MagazineBackCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected string $robots = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";

    /** @var array<int, array<string, mixed>> */
    protected array $posts = [];

    /** @var array<int, array<string, mixed>> */
    protected array $providers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => null, 'services.anthropic.key' => null, 'stoop.magazine_feed' => 'https://magazine.example/feed/', 'app.url' => 'https://stoop.example']);
        Http::preventStrayRequests();
        Sleep::fake();

        $long = str_repeat('A very long excerpt about the city. ', 40);
        $this->posts = [
            $this->wpPost(101, '2024-03-01T09:00:00', 'Where to Eat in Shaw', "<p>{$long} [&hellip;]</p>", [4], [11], 7, 501),
            $this->wpPost(102, '2024-03-02T09:00:00', 'A New Condo Rises', '<p>Big windows.</p>', [1789], [], 8, 502),
            $this->wpPost(103, '2024-03-03T09:00:00', 'Paid Post', '<p>Buy now.</p>', [99], [], 7, 0),
            $this->wpPost(104, '2024-03-04T09:00:00', 'Navy Yard&#8217;s Best Bar', '<p>Cheers.</p>', [4], [], 7, 0),
        ];
        $this->providers = [
            ['id' => 1, 'link' => 'https://magazine.example/100-very-best-restaurants-2026/albi/', 'title' => ['rendered' => 'Albi'], 'class_list' => ['provider', 'category-food', 'provider_type-100-very-best-restaurants-2026', 'locations-navy-yard']],
            ['id' => 2, 'link' => 'https://magazine.example/100-very-best-restaurants-2025/albi/', 'title' => ['rendered' => 'Albi'], 'class_list' => ['provider', 'category-food', 'provider_type-100-very-best-restaurants-2025']],
            ['id' => 3, 'link' => 'https://magazine.example/100-very-best-restaurants-2026/elsewhere/', 'title' => ['rendered' => 'Somewhere &amp; Else'], 'class_list' => ['provider', 'category-food', 'provider_type-100-very-best-restaurants-2026', 'locations-dc']],
            ['id' => 4, 'link' => 'https://magazine.example/best/restaurants/old/', 'title' => ['rendered' => 'Old Spot'], 'class_list' => ['provider', 'category-food', 'provider_type-restaurants', 'awards-eat-great-cheap-2019']],
        ];

        Http::fake(fn (Request $request) => $this->respond($request));
    }

    protected function wpPost(int $id, string $date, string $title, string $excerpt, array $categories, array $tags, int $author, int $media): array
    {
        return ['id' => $id, 'date' => $date, 'link' => "https://magazine.example/2024/03/post-$id/", 'title' => ['rendered' => $title],
            'excerpt' => ['rendered' => $excerpt, 'protected' => false], 'categories' => $categories, 'tags' => $tags, 'author' => $author, 'featured_media' => $media];
    }

    protected function respond(Request $request)
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
        $include = array_map('intval', explode(',', $q['include'] ?? ''));

        return match ($path) {
            '/robots.txt' => Http::response($this->robots),
            '/wp-json/wp/v2/posts' => Http::response(array_values(array_slice(array_filter($this->posts, fn ($p) => $p['date'] > $q['after']), 0, (int) $q['per_page']))),
            '/wp-json/wp/v2/categories' => Http::response(isset($q['slug']) ? [['id' => 4]] : [['id' => 4, 'name' => 'Food'], ['id' => 1789, 'name' => 'Real Estate'], ['id' => 99, 'name' => 'Sponsored']]),
            '/wp-json/wp/v2/tags' => Http::response(array_values(array_filter([['id' => 11, 'name' => 'Shaw']], fn ($t) => in_array($t['id'], $include, true)))),
            '/wp-json/wp/v2/media' => Http::response(array_values(array_filter([
                ['id' => 501, 'source_url' => 'https://magazine.example/wp-content/uploads/shaw.jpg'],
                ['id' => 502, 'source_url' => 'https://elsewhere.example/condo.jpg'],
            ], fn ($m) => in_array($m['id'], $include, true)))),
            '/wp-json/wp/v2/users' => Http::response(array_values(array_filter([
                ['id' => 7, 'name' => 'Ann Critic', 'slug' => 'acritic', 'description' => '<p>'.str_repeat('Eats a lot. ', 60).'</p>', 'avatar_urls' => ['96' => 'https://secure.gravatar.com/avatar/x?s=96']],
                ['id' => 8, 'name' => 'Rhea Estate', 'slug' => 'restate', 'description' => 'Writes about houses.', 'avatar_urls' => ['96' => 'https://magazine.example/wp-content/uploads/rhea.jpg']],
            ], fn ($u) => in_array($u['id'], $include, true)))),
            '/wp-json/wp/v2/provider' => Http::response(($q['page'] ?? 1) == 1 ? $this->providers : []),
            default => Http::response('', 404),
        };
    }

    protected function editor(array $roles = ['editor']): User
    {
        return User::factory()->create(['is_staff' => true, 'roles' => $roles]);
    }

    public function test_imports_metadata_only_into_the_archive_without_posting(): void
    {
        DB::table('station_aliases')->insert(['alias' => 'Navy Yard', 'stop' => 'F05', 'created_at' => now(), 'updated_at' => now()]);
        Author::forName('Rhea Estate')->forceFill(['title' => 'Real estate editor', 'photo_url' => 'https://magazine.example/staff.jpg'])->save();

        $result = MagazineBackCatalog::run(since: '2024-01-01');

        $this->assertSame(3, $result['imported'], 'The sponsored post is skipped.');
        $this->assertTrue($result['done']);
        $this->assertSame(0, Dispatch::count(), 'Older articles never post to the feed.');

        $shaw = DB::table('archive_stories')->where('url', 'https://magazine.example/2024/03/post-101/')->first();
        $this->assertSame('Food', $shaw->section);
        $this->assertSame('red', $shaw->line);
        $this->assertSame('Shaw', $shaw->tags);
        $this->assertSame('https://magazine.example/wp-content/uploads/shaw.jpg', $shaw->image_url);
        $this->assertStringNotContainsString('[…]', $shaw->description);
        $this->assertSame(['E02'], DB::table('archive_story_stations')->where('archive_story_id', $shaw->id)->pluck('stop')->all(), 'Tagged from the Shaw tag.');

        $condo = DB::table('archive_stories')->where('title', 'A New Condo Rises')->first();
        $this->assertNull($condo->image_url, 'Images on other domains are dropped.');
        $this->assertSame('green', $condo->line);
        $this->assertSame('Navy Yard’s Best Bar', DB::table('archive_stories')->where('url', 'like', '%post-104/')->value('title'));
        $this->assertSame(['F05'], DB::table('archive_story_stations')->join('archive_stories', 'archive_stories.id', '=', 'archive_story_id')->where('url', 'like', '%post-104/')->pluck('stop')->all());

        // Writers credited by byline; bios filled, staff edits and third-party avatars left alone.
        $ann = Author::where('slug', 'ann-critic')->first();
        $this->assertSame(2, DB::table('archive_story_author')->where('author_id', $ann->id)->count());
        $this->assertNull($ann->photo_url);
        $this->assertLessThanOrEqual(300, mb_strlen($ann->bio));
        $rhea = Author::where('slug', 'rhea-estate')->first();
        $this->assertSame(['Real estate editor', 'https://magazine.example/staff.jpg', 'Writes about houses.'], [$rhea->title, $rhea->photo_url, $rhea->bio]);

        // Metadata only: no stored text is longer than the trimmed excerpt.
        foreach (DB::table('archive_stories')->get() as $story) {
            foreach ((array) $story as $field => $value) {
                $this->assertLessThanOrEqual(500, mb_strlen((string) $value), $field);
                if (! in_array($field, ['url', 'image_url'], true)) {
                    $this->assertLessThanOrEqual(300, mb_strlen((string) $value), $field);
                }
            }
        }

        Http::assertSent(fn (Request $r) => $r->hasHeader('User-Agent', 'StoopBot/1.0 (+https://stoop.example)'));
        Http::assertSentCount(6); // robots, posts, media, users, tags, categories: one lookup each for the page
    }

    public function test_importing_twice_changes_nothing(): void
    {
        MagazineBackCatalog::run(since: '2024-01-01');
        $before = [DB::table('archive_stories')->count(), DB::table('archive_story_stations')->count(), DB::table('archive_story_author')->count(), Author::count()];

        MagazineBackCatalog::run(since: '2024-01-01');

        $this->assertSame($before, [DB::table('archive_stories')->count(), DB::table('archive_story_stations')->count(), DB::table('archive_story_author')->count(), Author::count()]);
    }

    public function test_resumes_from_the_saved_cursor(): void
    {
        $first = MagazineBackCatalog::run(max: 2, since: '2024-01-01');
        $this->assertSame(2, $first['imported']);
        $this->assertFalse($first['done']);
        $this->assertSame('2024-03-02T08:59:59', MagazineBackCatalog::status()['cursor']);

        $second = MagazineBackCatalog::run();

        $this->assertSame(2, $second['imported'], 'Picks up post 104 after the skipped sponsored post, and re-saves 102 harmlessly.');
        $this->assertTrue($second['done']);
        $this->assertSame(3, DB::table('archive_stories')->count());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/posts?') && str_contains(urldecode($r->url()), 'after=2024-03-02T08:59:59'));
    }

    public function test_defaults_to_three_years_back(): void
    {
        $this->assertSame(now(config('stoop.timezone'))->subYears(3)->format('Y-m-d').'T00:00:00', MagazineBackCatalog::status()['cursor']);
    }

    public function test_obeys_robots_txt(): void
    {
        $this->robots = "User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nDisallow: /wp-json/wp/v2/media\n";

        MagazineBackCatalog::run(since: '2024-01-01');

        $this->assertSame(3, DB::table('archive_stories')->count());
        $this->assertSame(0, DB::table('archive_stories')->whereNotNull('image_url')->count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/wp/v2/media'));

        $this->robots = "User-agent: StoopBot\nDisallow: /wp-json/\n";
        cache()->flush();
        DB::table('archive_stories')->delete();
        $result = MagazineBackCatalog::run(since: '2024-01-01');
        $this->assertNotNull($result['error']);
        $this->assertSame(0, DB::table('archive_stories')->count());
    }

    public function test_backfills_images_and_writers_for_posted_articles(): void
    {
        $id = DB::table('articles')->insertGetId(['guid_hash' => hash('sha256', 'x'), 'url' => 'https://magazine.example/2024/03/post-101/?utm_source=rss', 'title' => 'Where to Eat in Shaw', 'created_at' => now(), 'updated_at' => now()]);

        $result = MagazineBackCatalog::run(since: '2024-01-01');

        $this->assertSame(1, $result['backfilled']);
        $this->assertSame('https://magazine.example/wp-content/uploads/shaw.jpg', DB::table('articles')->where('id', $id)->value('image_url'));
        $this->assertSame(['Ann Critic'], Author::query()->join('article_author', 'author_id', '=', 'authors.id')->where('article_id', $id)->pluck('name')->all());
        $this->assertSame(0, Dispatch::count());
    }

    public function test_imports_the_latest_lists_into_places_matching_by_name(): void
    {
        DB::table('station_aliases')->insert(['alias' => 'Navy Yard', 'stop' => 'F05', 'created_at' => now(), 'updated_at' => now()]);
        $albi = Place::create(['name' => 'albi', 'blurb' => 'Staff wrote this.']);

        $result = MagazineBackCatalog::importLists();

        $this->assertSame(['100 Very Best Restaurants 2026' => 2], $result['lists'], 'Only the newest edition; old awards are skipped.');
        $this->assertSame(['created' => 1, 'updated' => 1], array_intersect_key($result, ['created' => 0, 'updated' => 0]));

        $albi->refresh();
        $this->assertSame(['100 Very Best Restaurants 2026', 'Navy Yard', 'F05', 'Staff wrote this.', null], [$albi->list_name, $albi->neighborhood, $albi->stop, $albi->blurb, $albi->list_rank]);
        $this->assertSame('https://magazine.example/100-very-best-restaurants-2026/albi/', $albi->article_url);

        $other = Place::where('name', 'Somewhere & Else')->first();
        $this->assertNull($other->stop);
        $this->assertNull($other->neighborhood);

        // Running again updates in place.
        MagazineBackCatalog::importLists();
        $this->assertSame(2, Place::count());

        // Places without a station are listed for editors.
        Livewire::actingAs($this->editor())->test(ArchiveAdmin::class)->assertSee('List places that need a station (1)')->assertSee('Somewhere &amp; Else', false);

        $this->assertSame(['Eat Great Cheap 2019' => 1], MagazineBackCatalog::importLists(['eat-great-cheap-2019'])['lists'], 'Older lists by name.');
    }

    public function test_admin_panel_is_for_editors(): void
    {
        $moderator = $this->editor(['moderator']);
        $this->actingAs($moderator)->get(route('admin.archive'))->assertForbidden();
        Livewire::actingAs($moderator)->test(ArchiveAdmin::class)->assertForbidden();

        Livewire::actingAs($this->editor())->test(ArchiveAdmin::class)
            ->assertSee('Archive import')->assertSee('Never')
            ->call('runBatch')
            ->assertSee('2024-03-04T08:59:59');
        $this->assertSame(3, DB::table('archive_stories')->count());
    }

    public function test_search_shows_archive_stories_from_the_magazine(): void
    {
        MagazineBackCatalog::run(since: '2024-01-01');

        Livewire::test(Search::class, ['q' => 'condo rises'])->assertSee('From the magazine')->assertSee('A New Condo Rises')->assertDontSee('Where to Eat in Shaw');
        Livewire::test(Search::class, ['q' => 'nothing like this'])->assertDontSee('From the magazine');
    }
}
