<?php

namespace Tests\Feature;

use App\Livewire\Composer;
use App\Livewire\Pages\Feed;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\LinkPreviews;
use App\Support\Mentions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_mentions_notify_and_link_but_emails_and_urls_do_not_count(): void
    {
        $maya = User::factory()->create(['username' => 'maya']);

        $this->assertSame(['maya'], Mentions::usernames('hey @maya, mail me at a@b.com or see https://x.com/@bob'));
        $this->assertStringContainsString('href="'.url('@maya').'"', (string) Mentions::render('hi @maya <b>'));
        $this->assertStringContainsString('&lt;b&gt;', (string) Mentions::render('hi @maya <b>'));

        Livewire::actingAs(User::factory()->create())->test(Composer::class)
            ->set('body', 'Ask @maya about the dessert')
            ->call('file');

        $this->assertSame('mention', $maya->notifications()->first()->data['kind']);
    }

    public function test_first_post_requires_accepting_the_rules(): void
    {
        $user = User::factory()->create(['accepted_rules_at' => null]);

        $component = Livewire::actingAs($user)->test(Composer::class)
            ->set('body', 'First post on the block')
            ->call('file')
            ->assertHasErrors('body');
        $this->assertDatabaseCount('dispatches', 0);

        $component->call('acceptRules')->call('file')->assertHasNoErrors();
        $this->assertDatabaseCount('dispatches', 1);
    }

    public function test_polls_close_and_stop_taking_votes(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Composer::class)
            ->set('type', 'poll')
            ->set('body', 'Best bagel?')
            ->set('options', ['Call Your Mother', 'Bullfrog', '', ''])
            ->set('pollHours', 1)
            ->call('file');

        $poll = Dispatch::first();
        $this->assertEqualsWithDelta(now()->addHour()->timestamp, $poll->closes_at->timestamp, 5);

        $this->travel(2)->hours();
        Livewire::actingAs(User::factory()->create())->test(Feed::class)->call('vote', $poll->id, $poll->options->first()->id);
        $this->assertDatabaseCount('poll_votes', 0);
    }

    public function test_scheduled_dispatches_publish_on_time(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Composer::class)
            ->set('body', 'Farmers market opens Saturday')
            ->set('publishAt', now()->addHours(2)->format('Y-m-d\TH:i'))
            ->call('file')
            ->assertHasNoErrors();

        $this->assertSame('scheduled', Dispatch::first()->status);
        $this->actingAs(User::factory()->create())->get('/?tab=latest')->assertDontSee('Farmers market opens Saturday');

        $this->travel(3)->hours();
        $this->artisan('stoop:publish-scheduled');

        $this->assertSame('approved', Dispatch::first()->status);
        $this->get('/?tab=latest')->assertSee('Farmers market opens Saturday');
    }

    public function test_delete_can_be_undone_and_later_leaves_a_tombstone_for_replies(): void
    {
        $user = User::factory()->create();
        $dispatch = Dispatch::factory()->for($user)->create(['body' => 'Original post']);
        Dispatch::factory()->create(['parent_id' => $dispatch->id, 'body' => 'A reply that should survive']);

        Livewire::actingAs($user)->test(Feed::class)
            ->call('deleteDispatch', $dispatch->id)
            ->assertSet('undoId', $dispatch->id)
            ->call('restoreDispatch', $dispatch->id);
        $this->assertFalse($dispatch->fresh()->trashed());

        $dispatch->delete();
        $this->travel(2)->minutes();
        $this->artisan('stoop:purge-deleted');

        $this->assertSame('', Dispatch::withTrashed()->find($dispatch->id)->body);
        $this->get(route('dispatches.show', $dispatch->id))->assertOk()->assertSee('This dispatch was deleted')->assertSee('A reply that should survive');
    }

    public function test_pinning_puts_a_dispatch_first_on_the_profile(): void
    {
        $user = User::factory()->create();
        $old = Dispatch::factory()->for($user)->create(['body' => 'Old but pinned', 'created_at' => now()->subWeek()]);
        Dispatch::factory()->for($user)->create(['body' => 'Newer post']);

        Livewire::actingAs($user)->test(Feed::class)->call('togglePin', $old->id);

        $this->assertSame($old->id, $user->fresh()->pinned_dispatch_id);
        $this->get(route('users.show', $user))->assertSeeInOrder(['Pinned', 'Old but pinned', 'Newer post']);
    }

    public function test_link_cards_are_fetched_from_public_hosts_only(): void
    {
        Http::fake(['https://example.com/*' => Http::response('<html><head><meta property="og:title" content="Best Pizza in DC"><meta name="description" content="Our picks"></head></html>', 200, ['Content-Type' => 'text/html'])]);

        $this->assertFalse(LinkPreviews::isPublic('http://127.0.0.1/admin'));
        $this->assertFalse(LinkPreviews::isPublic('http://10.0.0.5/'));
        $this->assertFalse(LinkPreviews::isPublic('file:///etc/passwd'));
        $this->assertFalse(LinkPreviews::isPublic('http://169.254.169.254/latest/meta-data'));
        $this->assertFalse(LinkPreviews::isPublic('http://[::1]/'));
        $this->assertFalse(LinkPreviews::isPublic('http://[fd00::1]/'));
        $this->assertFalse(LinkPreviews::isPublic('http://[::ffff:10.0.0.1]/'));
        $this->assertFalse(LinkPreviews::isPublic('http://localhost/'));
        $this->assertFalse(LinkPreviews::isPublic('http://example.com:8080/'));

        Livewire::actingAs(User::factory()->create())->test(Composer::class)
            ->set('body', 'Agree with this list https://example.com/pizza')
            ->call('file');

        $this->assertSame('Best Pizza in DC', Dispatch::first()->linkPreview?->title);
    }

    public function test_photos_are_stored_without_their_original_metadata(): void
    {
        Storage::fake('public');

        Livewire::actingAs(User::factory()->create())->test(Composer::class)
            ->set('body', 'The line at the pupusa window')
            ->set('photos', [UploadedFile::fake()->image('line.jpg', 800, 600)])
            ->set('alts', ['A line of people outside a takeout window'])
            ->call('file')
            ->assertHasNoErrors();

        $image = Dispatch::first()->images->first();
        $this->assertStringEndsWith('.webp', $image->path);
        $this->assertSame('A line of people outside a takeout window', $image->alt);
        Storage::disk('public')->assertExists($image->path);
    }

    public function test_share_images_render_as_png(): void
    {
        $dispatch = Dispatch::factory()->create();

        $response = $this->get(route('dispatches.og', $dispatch))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame("\x89PNG", substr($response->getContent(), 0, 4));
        $this->get(route('dispatches.show', $dispatch))->assertSee('og:image', false);
    }
}
