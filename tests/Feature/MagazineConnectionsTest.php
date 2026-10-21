<?php

namespace Tests\Feature;

use App\Actions\DispatchActions;
use App\Livewire\Admin\Magazine as MagazineAdmin;
use App\Livewire\Pages\AuthorShow;
use App\Livewire\Pages\Feed;
use App\Models\Author;
use App\Models\Dispatch;
use App\Models\Place;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Support\Magazine;
use App\Support\QuoteRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MagazineConnectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function feed(string $items): void
    {
        config(['stoop.magazine_feed' => 'https://magazine.example/feed']);
        Http::fake([
            'magazine.example/feed' => Http::response('<?xml version="1.0"?><rss xmlns:dc="http://purl.org/dc/elements/1.1/"><channel>'.$items.'</channel></rss>'),
            '*' => Http::response('', 404),
        ]);
    }

    public function test_neighborhood_box_shows_approved_dispatches_with_cors_for_magazine_origins_only(): void
    {
        config(['stoop.magazine_origins' => ['https://www.washingtonian.com']]);
        Dispatch::factory()->create(['stop' => 'E02', 'body' => 'New bakery on 7th is great']);
        Dispatch::factory()->create(['stop' => 'E02', 'body' => 'Held one', 'status' => 'held']);
        Dispatch::factory()->create(['stop' => 'E02', 'type' => 'safety', 'body' => 'Safety post']);

        $this->getJson('/api/embed/neighborhood/e02', ['Origin' => 'https://www.washingtonian.com'])
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://www.washingtonian.com')
            ->assertJsonPath('code', 'E02')->assertJsonCount(1, 'dispatches')
            ->assertJsonPath('dispatches.0.body', 'New bakery on 7th is great');

        $this->getJson('/api/embed/neighborhood/E02', ['Origin' => 'https://evil.example'])
            ->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin')->assertHeaderMissing('Set-Cookie');
        $this->getJson('/api/embed/neighborhood/ZZ9')->assertNotFound();
    }

    public function test_place_box_shows_been_count_and_check_ins_by_slug_or_id(): void
    {
        $place = Place::create(['name' => 'Le Diplomate', 'stop' => 'E02']);
        $post = Dispatch::factory()->create(['type' => 'been', 'place_id' => $place->id, 'body' => 'The steak frites!']);
        $post->user->marked()->attach($post->id, ['kind' => 'been']);
        User::factory()->create()->marked()->attach($post->id, ['kind' => 'been']);

        $this->getJson('/api/embed/places/le-diplomate')->assertOk()
            ->assertJsonPath('been_count', 2)->assertJsonPath('checkins.0.body', 'The steak frites!');
        $this->getJson('/api/embed/places/'.$place->id)->assertOk()->assertJsonPath('name', 'Le Diplomate');
        $this->get(route('embed'))->assertSee('data-stoop-neighborhood', false)->assertSee('data-stoop-place', false);
    }

    public function test_quotes_need_the_authors_consent_which_is_logged(): void
    {
        $editor = User::factory()->create(['is_staff' => true]);
        $post = Dispatch::factory()->create(['body' => 'The 14th Street bus lane changed my commute']);
        $author = $post->user;

        $this->assertNull(QuoteRequests::request($editor, $post));
        $this->assertNotNull(QuoteRequests::request($editor, $post), 'One open request at a time.');
        $request = QuoteRequest::first();
        $this->assertSame('quote_request', $author->notifications()->first()->data['kind']);
        $this->getJson("/api/embed/quotes/{$request->id}")->assertNotFound();

        // Only the author answers.
        $this->actingAs(User::factory()->create())->post(route('quote-requests.answer', [$request, 'allow']))->assertForbidden();
        $this->actingAs($author)->get(route('notifications'))->assertSee('Allow')->assertSee('Decline');
        $this->actingAs($author)->post(route('quote-requests.answer', [$request, 'allow']))->assertRedirect();

        $this->getJson("/api/embed/quotes/{$request->id}")->assertOk()
            ->assertJsonPath('type', 'rich')->assertJsonPath('text', 'The 14th Street bus lane changed my commute')
            ->assertJsonPath('author_name', '@'.$author->username);

        // Withdrawing takes it down.
        $this->actingAs($author)->post(route('quote-requests.answer', [$request, 'decline']))->assertRedirect();
        $this->getJson("/api/embed/quotes/{$request->id}")->assertNotFound();

        $log = DB::table('quote_consents')->where('quote_request_id', $request->id)->orderBy('id')->get();
        $this->assertSame(['requested', 'allowed', 'revoked'], $log->pluck('action')->all());
        $this->assertSame($author->id, $log[1]->user_id);
        $this->assertSame(hash('sha256', $post->body), $log[1]->body_hash);
    }

    public function test_quote_requests_expire_and_deleting_the_dispatch_removes_the_card(): void
    {
        $editor = User::factory()->create(['is_staff' => true]);
        $post = Dispatch::factory()->create();

        $this->assertHttpError(fn () => QuoteRequests::request(User::factory()->create(), $post), 403);

        QuoteRequests::request($editor, $post);
        $request = QuoteRequest::first();
        $this->travel(15)->days();
        $this->actingAs($post->user)->post(route('quote-requests.answer', [$request, 'allow']))->assertStatus(422);
        $this->assertSame('expired', $request->fresh()->state());

        $this->assertNull(QuoteRequests::request($editor, $post), 'An expired request can be asked again.');
        $second = QuoteRequest::latest('id')->first();
        QuoteRequests::decide($post->user, $second, true);
        $this->getJson("/api/embed/quotes/{$second->id}")->assertOk();
        $post->delete();
        $this->getJson("/api/embed/quotes/{$second->id}")->assertNotFound();
    }

    public function test_staff_see_request_to_quote_in_the_card_menu(): void
    {
        Dispatch::factory()->create(['body' => 'Quotable']);

        Livewire::actingAs(User::factory()->create(['is_staff' => true]))->test(Feed::class)->assertSee('Request to quote');
        Livewire::actingAs(User::factory()->create())->test(Feed::class)->assertDontSee('Request to quote');
    }

    public function test_editors_post_article_polls_with_live_results_in_the_embed(): void
    {
        $editor = User::factory()->create(['is_staff' => true]);
        Livewire::actingAs(User::factory()->create())->test(MagazineAdmin::class)->assertForbidden();

        Livewire::actingAs($editor)->test(MagazineAdmin::class)
            ->set('pollArticle', 'https://magazine.example/best-pizza')
            ->set('pollQuestion', 'Best pizza in DC?')
            ->set('pollOptions', ['2Amys', 'Timber', '', ''])
            ->call('createPoll')->assertHasNoErrors()->assertSee('data-stoop-poll');

        $poll = Dispatch::where('type', 'poll')->firstOrFail();
        $this->assertSame(config('stoop.magazine_account'), $poll->user->username);
        $this->assertSame('https://magazine.example/best-pizza', $poll->details['article_url']);

        $option = $poll->options()->first();
        foreach (User::factory()->count(3)->create() as $voter) {
            DispatchActions::vote($voter, $poll, $option->id);
        }

        $this->getJson("/api/embed/polls/{$poll->id}")->assertOk()
            ->assertJsonPath('total', 3)->assertJsonPath('options.0.percent', 100)
            ->assertJsonPath('options.1.votes', 0)->assertJsonPath('url', route('dispatches.show', $poll));
        $this->getJson('/api/embed/polls/'.Dispatch::factory()->create()->id)->assertNotFound();
    }

    public function test_imported_bylines_become_writers_credited_on_magazine_posts(): void
    {
        $this->feed('
            <item><title>The 100 Best Restaurants</title><link>https://magazine.example/100-best</link><guid>b1</guid><category>Food</category>
                <dc:creator><![CDATA[Ann Limpert and Jessica Sidman]]></dc:creator></item>
            <item><title>Where to Drink Now</title><link>https://magazine.example/drink</link><guid>b2</guid><category>Food</category>
                <dc:creator>Jessica Sidman</dc:creator><dc:creator>Andrew Beaujon</dc:creator><author>desk@magazine.example</author></item>');

        $follower = User::factory()->create();
        Author::forName('Jessica Sidman')->followers()->attach($follower->id);

        $this->assertSame(2, Magazine::import());
        $this->assertSame(['Andrew Beaujon', 'Ann Limpert', 'Jessica Sidman'], Author::orderBy('name')->pluck('name')->all(), 'No email addresses, no duplicates.');

        $sidman = Author::firstWhere('slug', 'jessica-sidman');
        $this->assertSame(2, $sidman->articles()->count());
        $this->assertCount(2, $follower->notifications, 'Followers hear about each new story.');
        $this->assertSame('author', $follower->notifications->first()->data['kind']);

        $post = Dispatch::where('body', 'like', '%100 Best%')->first();
        $this->assertSame(['Ann Limpert', 'Jessica Sidman'], Author::forDispatch($post->id)->pluck('name')->all());
        Livewire::test(Feed::class)->assertSee('By')->assertSee(route('authors.show', $sidman), false);
    }

    public function test_author_page_byline_embed_and_following(): void
    {
        $author = Author::forName('Ann Limpert');
        $author->update(['title' => 'Food critic']);
        $id = DB::table('articles')->insertGetId(['guid_hash' => 'x', 'url' => 'https://magazine.example/pho', 'title' => 'Best Pho', 'created_at' => now(), 'updated_at' => now(), 'published_at' => now()]);
        DB::table('article_author')->insert(['article_id' => $id, 'author_id' => $author->id]);

        $this->get(route('authors.show', $author))->assertOk()->assertSee('Ann Limpert')->assertSee('Food critic')->assertSee('Best Pho');

        $reader = User::factory()->create();
        Livewire::actingAs($reader)->test(AuthorShow::class, ['author' => $author])->call('toggleFollow')->assertSee('Following');
        $this->assertTrue($author->followers()->whereKey($reader->id)->exists());
        auth()->logout();
        Livewire::test(AuthorShow::class, ['author' => $author])->call('toggleFollow')->assertRedirect(route('login'));

        $this->getJson('/api/embed/authors/ann-limpert')->assertOk()
            ->assertJsonPath('title', 'Food critic')->assertJsonPath('followers', 1)
            ->assertJsonPath('url', route('authors.show', $author))->assertJsonPath('articles', 1);
    }

    public function test_staff_edit_writers_and_connect_a_stoop_account(): void
    {
        $author = Author::forName('Ann Limpert');
        $writer = User::factory()->create(['username' => 'annlimpert']);

        Livewire::actingAs(User::factory()->create(['is_staff' => true]))->test(MagazineAdmin::class)
            ->call('edit', $author->id)->set('title', 'Food critic')->set('username', 'annlimpert')->set('photoUrl', 'http://insecure.example/a.jpg')
            ->call('save')->assertHasErrors('photoUrl')
            ->set('photoUrl', '')->call('save')->assertHasNoErrors();

        $this->assertSame($writer->id, $author->fresh()->user_id);
        $this->assertSame('Food critic', $author->fresh()->title);
        $this->assertDatabaseHas('audit_logs', ['action' => 'author.updated']);
    }

    public function test_backfill_credits_older_articles_from_the_article_page(): void
    {
        config(['stoop.magazine_feed' => null]);
        Http::fake(['magazine.example/*' => Http::response('<html><head><meta name="author" content="Andrew Beaujon &amp; Ann Limpert"></head></html>')]);
        $id = DB::table('articles')->insertGetId(['guid_hash' => 'old', 'url' => 'https://magazine.example/old', 'title' => 'Old story', 'created_at' => now(), 'updated_at' => now()]);
        $follower = User::factory()->create();

        $this->artisan('stoop:backfill-authors')->expectsOutputToContain('Credited writers on 1 articles')->assertSuccessful();
        $this->assertSame(2, DB::table('article_author')->where('article_id', $id)->count());
        $this->artisan('stoop:backfill-authors')->expectsOutputToContain('on 0 articles');
        $this->assertCount(0, $follower->notifications);
    }

    protected function assertHttpError(callable $fn, int $status): void
    {
        try {
            $fn();
            $this->fail('Expected an HTTP error.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }
}
