<?php

namespace Tests\Feature;

use App\Livewire\Composer;
use App\Livewire\Pages\Feed;
use App\Livewire\Pages\Moderation;
use App\Models\Dispatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_read_the_feed_but_not_post(): void
    {
        Dispatch::factory()->create(['body' => 'Farmers market is packed today']);

        $this->get('/')->assertOk()->assertSee('Farmers market is packed today');

        Livewire::test(Composer::class)
            ->set('body', 'Hello neighbors')
            ->call('file')
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('dispatches', 1);
    }

    public function test_a_subscriber_can_file_a_dispatch(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Composer::class)
            ->set('body', 'Red Line is single-tracking at Dupont')
            ->set('line', 'red')
            ->set('stop', 'A03')
            ->call('file')
            ->assertHasNoErrors()
            ->assertDispatched('dispatch-filed');

        $this->assertDatabaseHas('dispatches', ['user_id' => $user->id, 'stop' => 'A03', 'status' => 'approved']);
    }

    public function test_only_staff_can_post_callouts(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Composer::class)
            ->set('type', 'callout')
            ->set('body', 'Tell us your favorite crab cake')
            ->call('file')
            ->assertHasErrors('type');
    }

    public function test_a_reply_notifies_the_author(): void
    {
        $dispatch = Dispatch::factory()->create();
        $replier = User::factory()->create();

        Livewire::actingAs($replier)->test(Composer::class, ['parentId' => $dispatch->id])
            ->set('body', 'Went last week, worth the line')
            ->call('file')
            ->assertHasNoErrors();

        $this->assertSame(1, $dispatch->replies()->count());
        $this->assertSame('reply', $dispatch->user->notifications()->first()->data['kind']);
    }

    public function test_heated_posts_get_a_nudge_before_going_out(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(Composer::class)
            ->set('body', 'These people are idiots')
            ->call('file')
            ->assertSet('warned', true);

        $this->assertDatabaseCount('dispatches', 0);

        $component->call('file');
        $this->assertDatabaseCount('dispatches', 1);
    }

    public function test_muted_users_cannot_post(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['muted_until' => now()->addDay()])->save();

        Livewire::actingAs($user)->test(Composer::class)
            ->set('body', 'Can anyone hear me')
            ->call('file')
            ->assertHasErrors('body');

        $this->assertDatabaseCount('dispatches', 0);
    }

    public function test_polls_take_one_vote_per_person(): void
    {
        $user = User::factory()->create();
        $poll = Dispatch::factory()->create(['type' => 'poll']);
        [$a, $b] = $poll->options()->createMany([['label' => 'Half-smoke'], ['label' => 'Mumbo wings']]);

        Livewire::actingAs($user)->test(Feed::class)
            ->call('vote', $poll->id, $a->id)
            ->call('vote', $poll->id, $b->id);

        $this->assertDatabaseCount('poll_votes', 1);
        $this->assertDatabaseHas('poll_votes', ['user_id' => $user->id, 'poll_option_id' => $a->id]);
    }

    public function test_following_tab_only_shows_people_you_follow(): void
    {
        $user = User::factory()->create();
        $followed = Dispatch::factory()->create(['body' => 'From someone I follow']);
        Dispatch::factory()->create(['body' => 'From a stranger']);
        $user->following()->attach($followed->user_id);

        Livewire::actingAs($user)->test(Feed::class)
            ->call('setTab', 'following')
            ->assertSee('From someone I follow')
            ->assertDontSee('From a stranger');
    }

    public function test_reporting_hides_for_the_reporter_then_for_everyone_at_the_threshold(): void
    {
        $dispatch = Dispatch::factory()->create(['body' => 'Something hateful']);
        $reporters = User::factory()->count(config('stoop.report_threshold'))->create();

        Livewire::actingAs($reporters[0])->test(Feed::class)
            ->call('startReport', $dispatch->id)
            ->set('reportReason', 'hate')
            ->call('submitReport')
            ->assertDontSee('Something hateful');

        $this->assertSame('approved', $dispatch->fresh()->status);

        foreach ($reporters->slice(1) as $reporter) {
            Livewire::actingAs($reporter)->test(Feed::class)
                ->call('startReport', $dispatch->id)
                ->call('submitReport');
        }

        $this->assertSame('hidden', $dispatch->fresh()->status);
        $this->get('/')->assertDontSee('Something hateful');
    }

    public function test_blocking_hides_posts_both_ways(): void
    {
        $user = User::factory()->create();
        $other = Dispatch::factory()->create(['body' => 'Blocked person post']);
        Dispatch::factory()->for($user)->create(['body' => 'My own post']);

        Livewire::actingAs($user)->test(Feed::class)
            ->call('silence', $other->user_id, 'block')
            ->assertDontSee('Blocked person post');

        Livewire::actingAs($other->user)->test(Feed::class)
            ->assertDontSee('My own post');
    }

    public function test_moderation_is_staff_only(): void
    {
        $this->actingAs(User::factory()->create())->get(route('moderation'))->assertForbidden();

        $staff = User::factory()->create();
        $staff->forceFill(['is_staff' => true])->save();
        $hidden = Dispatch::factory()->create(['status' => 'hidden']);

        Livewire::actingAs($staff)->test(Moderation::class)
            ->assertSee($hidden->body)
            ->call('approve', $hidden->id);

        $this->assertSame('approved', $hidden->fresh()->status);
    }

    public function test_every_page_renders_for_a_signed_in_staff_member(): void
    {
        $this->seed();
        $staff = User::where('username', 'food')->first();

        foreach (['/', '/?tab=following', '/?tab=near', '/?line=red', '/?q=lunch', '/saved', '/notifications', '/moderation', '/@mayaeats', '/d/1', '/rules', '/settings/profile'] as $url) {
            $this->actingAs($staff)->get($url)->assertOk();
        }
    }
}
