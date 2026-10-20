<?php

namespace Tests\Feature;

use App\Livewire\Composer;
use App\Livewire\Pages\Feed;
use App\Livewire\Pages\UserProfile;
use App\Livewire\Settings\ApiTokens;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\Achievements;
use App\Support\Bots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class CommunityTest extends TestCase
{
    use RefreshDatabase;

    public function test_half_smokes_are_opt_in_and_capped_at_ten_a_day(): void
    {
        $giver = User::factory()->create(['half_smokes_on' => true]);
        $post = Dispatch::factory()->create();

        $feed = Livewire::actingAs($giver)->test(Feed::class);
        foreach (range(1, 12) as $i) {
            $feed->call('giveHalfSmoke', $post->id);
        }

        $this->assertSame(10, $post->halfSmokes()->count());
        $this->assertSame(0, $giver->halfSmokesLeft());

        $this->travelTo(now(config('stoop.timezone'))->addDay()->startOfDay()->addMinute());
        $this->assertSame(10, $giver->halfSmokesLeft());

        Livewire::actingAs(User::factory()->create())->test(Feed::class)->call('giveHalfSmoke', $post->id);
        $this->assertSame(10, $post->halfSmokes()->count());
    }

    public function test_badges_xp_and_streaks_come_from_activity(): void
    {
        $user = User::factory()->create();
        foreach (array_keys(config('stoop.lines')) as $i => $line) {
            Dispatch::factory()->for($user)->create(['line' => $line, 'created_at' => now()->subDays($i)]);
        }

        $badges = Achievements::unlocked($user);

        $this->assertArrayHasKey('doors-open', $badges);
        $this->assertArrayHasKey('all-lines', $badges);
        $this->assertSame(6, Achievements::streaks($user)['longest']);
        $this->assertSame(60 + 90, Achievements::xp($user));
        $this->assertSame('Commuter', Achievements::rank(150));
    }

    public function test_profile_shows_the_ridership_graph_and_going_live(): void
    {
        $user = User::factory()->create();
        Dispatch::factory()->for($user)->create();

        Livewire::actingAs($user)->test(UserProfile::class, ['user' => $user])
            ->assertSee('Doors Open')
            ->set('liveUrl', 'https://evil.example.com/stream')->call('goLive')->assertHasErrors('liveUrl')
            ->set('liveUrl', 'https://www.youtube.com/live/abc123')->set('liveLabel', 'Navy Yard')->call('goLive')->assertHasNoErrors();

        $this->assertTrue($user->fresh()->isLive());
        $this->get(route('users.ridership', $user))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->get('/')->assertSee('ON THE SCENE');
    }

    public function test_bots_greet_and_answer_questions(): void
    {
        config(['services.anthropic.key' => null]);
        $user = User::factory()->create(['name' => 'Priya K.']);

        Livewire::actingAs($user)->test(Composer::class)->set('body', 'Hello DC!')->call('file');
        $greeting = Dispatch::where('user_id', Bots::account('dc')->id)->first();
        $this->assertSame('Welcome to the neighborhood, Priya K..', $greeting?->body);

        Livewire::actingAs($user)->test(Composer::class)->set('body', '@stoop how do half-smokes work?')->call('file');
        $answer = Dispatch::where('user_id', Bots::account('stoop')->id)->first();
        $this->assertStringContainsStringIgnoringCase('half-smokes', $answer?->body);
    }

    public function test_new_accounts_start_out_friends_with_stoop(): void
    {
        $this->post(route('register.store'), [
            'name' => 'New Neighbor', 'username' => 'newneighbor', 'email' => 'new@example.com',
            'password' => 'password-123-Long!', 'password_confirmation' => 'password-123-Long!',
        ]);

        $user = User::where('username', 'newneighbor')->firstOrFail();
        $this->assertTrue($user->following()->where('username', 'stoop')->exists());
        $this->assertTrue($user->followers()->where('username', 'stoop')->exists());
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_the_api_posts_with_the_same_rules_as_the_website(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['dispatch:write']);

        $this->postJson('/api/dispatches', ['body' => 'Red Line single-tracking at Dupont', 'line' => 'red', 'stop' => 'a03'])
            ->assertCreated()->assertJsonPath('data.stop.code', 'A03');

        $this->postJson('/api/dispatches', ['body' => 'these people are idiots'])->assertStatus(422);
        $this->postJson('/api/dispatches', ['body' => 'these people are idiots', 'confirm' => true])->assertCreated();
        $this->postJson('/api/dispatches', ['body' => 'x'])->assertStatus(422)->assertJsonValidationErrors('body');

        Sanctum::actingAs($user, []);
        $this->postJson('/api/dispatches', ['body' => 'No write scope here'])->assertForbidden();
    }

    public function test_public_api_feeds_and_terminal_script(): void
    {
        $post = Dispatch::factory()->create(['body' => 'Farmers market is open', 'line' => 'red', 'stop' => 'D06']);
        Dispatch::factory()->create(['status' => 'held', 'body' => 'Held post']);

        $this->getJson('/api/dispatches?line=red')->assertOk()->assertJsonPath('data.0.body', 'Farmers market is open')->assertJsonCount(1, 'data');
        $this->getJson("/api/dispatches/{$post->id}")->assertOk();
        $this->get(route('feeds.stop', 'd06'))->assertOk()->assertSee('Farmers market is open')->assertDontSee('Held post');
        $this->get(route('feeds.user', $post->user))->assertOk()->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8');
        $this->get(route('terminal'))->assertOk()->assertSee('STOOP_TOKEN');
    }

    public function test_tokens_are_shown_once_and_can_be_revoked(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(ApiTokens::class)->set('name', 'Laptop')->call('create');
        $this->assertNotNull($component->get('plainToken'));
        $this->assertTrue($user->tokens()->first()->can('dispatch:write'));

        $component->call('revoke', $user->tokens()->first()->id);
        $this->assertSame(0, $user->tokens()->count());
    }
}
