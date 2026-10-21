<?php

namespace Tests\Feature;

use App\Livewire\Pages\Feed;
use App\Livewire\Pages\Notifications;
use App\Models\Dispatch;
use App\Models\User;
use App\Notifications\StoopActivity;
use App\Support\Suggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_dispatches_wait_behind_a_button_instead_of_shifting_the_feed(): void
    {
        $user = User::factory()->create();
        Dispatch::factory()->create(['body' => 'Already here']);

        $feed = Livewire::actingAs($user)->test(Feed::class, ['tab' => 'latest'])->assertSee('Already here');

        $this->travel(1)->minutes();
        Dispatch::factory()->create(['body' => 'Just arrived']);

        $feed->call('$refresh')->assertDontSee('Just arrived')->assertSee('Show 1 new dispatch');
        $feed->call('showNew')->assertSee('Just arrived');
    }

    public function test_the_last_feed_opened_is_remembered(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Feed::class)->call('setTab', 'latest');

        Livewire::actingAs($user)->test(Feed::class)->assertSet('tab', 'latest');
    }

    public function test_suggestions_prefer_people_your_follows_follow(): void
    {
        [$me, $friend, $friendOfFriend, $popular] = User::factory()->count(4)->create();
        $me->following()->attach($friend);
        $friend->following()->attach($friendOfFriend);
        User::factory()->count(3)->create()->each(fn ($u) => $u->following()->attach($popular));

        $suggested = Suggestions::for($me, 2);

        $this->assertSame([$friendOfFriend->id, $popular->id], $suggested->pluck('id')->all());
        $this->assertSame('1 person you follow follows them', $suggested->first()->reason);
    }

    public function test_notifications_group_by_thread_and_filter_by_kind(): void
    {
        $me = User::factory()->create();
        $post = Dispatch::factory()->for($me)->create();
        foreach (User::factory()->count(3)->create() as $replier) {
            $reply = Dispatch::factory()->for($replier)->create(['parent_id' => $post->id]);
            $me->notify(new StoopActivity('reply', $replier, $reply));
        }
        $me->notify(new StoopActivity('follow', User::factory()->create(['username' => 'newfan'])));

        Livewire::actingAs($me)->test(Notifications::class)
            ->assertSee('and 2 others')
            ->assertSee('Follow back @newfan')
            ->set('filter', 'follow')
            ->assertDontSee('replied in your thread');
    }

    public function test_hover_cards_render(): void
    {
        $user = User::factory()->create(['username' => 'maya', 'name' => 'Maya R.']);

        $this->get(route('users.card', $user))->assertOk()->assertSee('Maya R.')->assertSee('followers');
    }

    public function test_empty_states_name_the_station(): void
    {
        $this->get('/?stop=E05')->assertOk()->assertSee('Quiet at Petworth')->assertDontSee('{{', false);
    }
}
