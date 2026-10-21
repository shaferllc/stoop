<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Dispatch;
use App\Models\Penalty;
use App\Models\User;
use App\Support\DailyQuiz;
use Database\Seeders\QuizQuestionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // Bikeshare and WMATA on station pages.
        config(['stoop.model_moderation' => false, 'services.openai.key' => null]);
    }

    protected function signIn(?User $user = null): User
    {
        $user ??= User::factory()->create();
        Sanctum::actingAs($user, ['app']);

        return $user;
    }

    public function test_login_issues_an_app_token_without_exposing_the_email(): void
    {
        $user = User::factory()->create(['email' => 'neighbor@example.com']);

        $this->postJson('/api/app/login', ['email' => 'neighbor@example.com', 'password' => 'wrong'])->assertStatus(422);

        $response = $this->postJson('/api/app/login', ['email' => 'neighbor@example.com', 'password' => 'password'])
            ->assertCreated()->assertJsonPath('user.username', $user->username)->assertJsonMissing(['email' => 'neighbor@example.com']);

        $this->assertStringNotContainsString('neighbor@example.com', $response->getContent());
        $this->assertSame(['app'], $user->tokens()->first()->abilities);

        $this->withToken($response->json('token'))->getJson('/api/app/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_two_factor_accounts_need_a_valid_code(): void
    {
        User::factory()->withTwoFactor()->create(['email' => 'tfa@example.com']);
        $this->mock(TwoFactorAuthenticationProvider::class, fn ($mock) => $mock->shouldReceive('verify')
            ->andReturnUsing(fn ($secret, $code) => $secret === 'secret' && $code === '123456'));

        $this->postJson('/api/app/login', ['email' => 'tfa@example.com', 'password' => 'password'])
            ->assertStatus(422)->assertJsonPath('two_factor_required', true);
        $this->postJson('/api/app/login', ['email' => 'tfa@example.com', 'password' => 'password', 'code' => '000000'])->assertStatus(422);
        $this->postJson('/api/app/login', ['email' => 'tfa@example.com', 'password' => 'password', 'code' => '123456'])->assertCreated();
        $this->postJson('/api/app/login', ['email' => 'tfa@example.com', 'password' => 'password', 'recovery_code' => 'recovery-code-1'])->assertCreated();
    }

    public function test_tokens_without_the_app_ability_are_refused(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['dispatch:write']);
        $this->getJson('/api/app/feed')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/app/feed')->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        User::factory()->create(['email' => 'out@example.com']);
        $token = $this->postJson('/api/app/login', ['email' => 'out@example.com', 'password' => 'password'])->json('token');

        $this->withToken($token)->postJson('/api/app/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_banned_accounts_see_the_ban_and_nothing_else(): void
    {
        $user = $this->signIn();
        Penalty::create(['user_id' => $user->id, 'subject_hash' => $user->subjectHash(), 'type' => 'ban', 'reason' => 'Threats']);

        $this->getJson('/api/app/me')->assertOk()->assertJsonPath('data.banned.reason', 'Threats');
        $this->getJson('/api/app/feed')->assertForbidden();
        $this->postJson('/api/app/dispatches', ['body' => 'Hello there DC'])->assertForbidden();
        $this->postJson('/api/app/logout')->assertOk();
    }

    public function test_timeouts_stop_posting_like_the_website(): void
    {
        $user = $this->signIn();
        Penalty::create(['user_id' => $user->id, 'subject_hash' => $user->subjectHash(), 'type' => 'timeout', 'reason' => 'Spam', 'ends_at' => now()->addDay()]);

        $this->getJson('/api/app/feed')->assertOk();
        $this->postJson('/api/app/dispatches', ['body' => 'Hello there DC', 'line' => 'red'])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->assertDatabaseCount('dispatches', 0);
    }

    public function test_feeds_show_visible_dispatches_with_viewer_state(): void
    {
        $user = $this->signIn(User::factory()->create(['stops' => ['A03']]));
        $friend = User::factory()->create();
        $user->following()->attach($friend);
        $mine = Dispatch::factory()->for($friend)->create(['stop' => 'A03', 'line' => 'red']);
        $other = Dispatch::factory()->create(['stop' => 'B01']);
        Dispatch::factory()->create(['status' => 'held']);
        $user->marked()->attach($mine->id, ['kind' => 'save']);

        $this->getJson('/api/app/feed?tab=latest')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/app/feed?tab=following')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)->assertJsonPath('data.0.viewer.saved', true)
            ->assertJsonPath('data.0.line.color', '#bf0d3e')->assertJsonPath('data.0.stop.name', 'Dupont Circle');
        $this->getJson('/api/app/feed?tab=near')->assertJsonCount(1, 'data');
        $this->getJson('/api/app/feed?tab=rush')->assertJsonCount(2, 'data');
        $this->getJson('/api/app/feed?tab=latest&stop=B01')->assertJsonPath('data.0.id', $other->id);
        $this->getJson('/api/app/saved')->assertJsonCount(1, 'data');
    }

    public function test_posting_runs_through_the_same_rules_as_the_website(): void
    {
        $user = $this->signIn(User::factory()->create(['accepted_rules_at' => null]));

        $this->postJson('/api/app/dispatches', ['body' => 'Best half-smoke near Navy Yard?', 'line' => 'red'])->assertStatus(422);
        $this->postJson('/api/app/rules/accept')->assertJsonPath('data.accepted_rules', true);

        $this->postJson('/api/app/dispatches', ['body' => 'Best half-smoke near Navy Yard?', 'type' => 'ask', 'line' => 'red', 'stop' => 'f05'])
            ->assertCreated()->assertJsonPath('data.line.key', 'yellow')->assertJsonPath('data.stop.code', 'F05')
            ->assertJsonPath('notice', 'Dispatch filed.')->assertJsonPath('unlocked.0.name', 'Doors Open');

        // The nudge asks first, then posts once confirmed.
        $this->postJson('/api/app/dispatches', ['body' => 'You are an idiot about pizza', 'line' => 'red'])
            ->assertStatus(422)->assertJsonPath('nudge', true);
        $this->postJson('/api/app/dispatches', ['body' => 'You are an idiot about pizza', 'line' => 'red', 'confirm' => true])->assertCreated();

        // Safety reports need a stop, and polls two options.
        $this->postJson('/api/app/dispatches', ['type' => 'safety', 'body' => 'Car break-ins on my block', 'safety_when' => 'Last night'])->assertJsonValidationErrors('stop');
        $this->postJson('/api/app/dispatches', ['type' => 'poll', 'body' => 'Best mumbo sauce?', 'options' => ['Yes']])->assertJsonValidationErrors('options');

        $this->assertSame(2, $user->dispatches()->count());
    }

    public function test_replies_and_threads(): void
    {
        $user = $this->signIn();
        $question = Dispatch::factory()->create(['type' => 'ask', 'line' => 'yellow']);
        Dispatch::factory()->create(['parent_id' => $question->id, 'status' => 'held']);

        $this->postJson('/api/app/dispatches', ['body' => 'Try Ben’s on U Street', 'parent_id' => $question->id])
            ->assertCreated()->assertJsonPath('data.parent_id', $question->id)->assertJsonPath('notice', 'Reply posted.');

        $this->getJson("/api/app/dispatches/{$question->id}")->assertOk()
            ->assertJsonPath('data.id', $question->id)->assertJsonCount(1, 'replies')
            ->assertJsonPath('replies.0.author.username', $user->username)
            ->assertJsonMissing(['email' => $question->user->email]);

        $this->getJson('/api/app/dispatches/'.Dispatch::factory()->create(['status' => 'held'])->id)->assertNotFound();
    }

    public function test_half_smokes_saves_votes_and_reports(): void
    {
        $user = $this->signIn(User::factory()->create(['half_smokes_on' => true]));
        $dispatch = Dispatch::factory()->create();

        $this->postJson("/api/app/dispatches/{$dispatch->id}/half-smoke")->assertOk()->assertJsonPath('count', 1)->assertJsonPath('left', 9);
        $this->postJson('/api/app/dispatches/'.Dispatch::factory()->for($user)->create()->id.'/half-smoke')->assertStatus(422);

        $this->postJson("/api/app/dispatches/{$dispatch->id}/marks/save")->assertJsonPath('on', true);
        $this->postJson("/api/app/dispatches/{$dispatch->id}/marks/save")->assertJsonPath('on', false);

        $poll = Dispatch::factory()->create(['type' => 'poll', 'closes_at' => now()->addDay()]);
        $option = $poll->options()->create(['label' => 'Ben’s']);
        $poll->options()->create(['label' => 'Weenie Beenie']);
        $this->getJson("/api/app/dispatches/{$poll->id}")->assertJsonPath('data.poll.options.0.votes', null);
        $this->postJson("/api/app/dispatches/{$poll->id}/vote", ['option_id' => $option->id])
            ->assertJsonPath('data.poll.my_vote', $option->id)->assertJsonPath('data.poll.options.0.votes', 1);

        $this->postJson("/api/app/dispatches/{$dispatch->id}/report", ['reason' => 'spam'])->assertOk();
        $this->getJson("/api/app/dispatches/{$dispatch->id}")->assertNotFound();
    }

    public function test_follow_block_and_profiles(): void
    {
        $user = $this->signIn();
        $neighbor = User::factory()->create(['email' => 'private@example.com', 'is_native' => true]);
        Dispatch::factory()->for($neighbor)->create(['stop' => 'A03', 'line' => 'red']);

        $this->postJson("/api/app/users/{$neighbor->username}/follow")->assertJsonPath('following', true);
        $this->assertTrue($user->following()->whereKey($neighbor->id)->exists());
        $this->assertSame('follow', $neighbor->notifications()->first()->data['kind']);

        $profile = $this->getJson("/api/app/users/{$neighbor->username}")->assertOk()
            ->assertJsonPath('data.badge', 'NATIVE')->assertJsonPath('data.is_following', true)
            ->assertJsonPath('data.visited', ['A03'])->assertJsonCount(1, 'data.dispatches');
        $this->assertStringNotContainsString('private@example.com', $profile->getContent());

        $this->postJson("/api/app/users/{$neighbor->username}/block")->assertOk();
        $this->assertFalse($user->following()->whereKey($neighbor->id)->exists());
        $this->getJson("/api/app/users/{$neighbor->username}")->assertNotFound();
        $this->getJson('/api/app/feed?tab=latest')->assertJsonCount(0, 'data');
    }

    public function test_notifications_hide_muted_people_and_mark_read(): void
    {
        $user = $this->signIn();
        $fan = User::factory()->create(['email' => 'fan@example.com']);
        $muted = User::factory()->create();
        $this->signIn($fan);
        $this->postJson("/api/app/users/{$user->username}/follow")->assertOk();
        $this->signIn($muted);
        $this->postJson("/api/app/users/{$user->username}/follow")->assertOk();
        $user->silenced()->attach($muted->id, ['type' => 'mute']);

        $this->signIn($user);
        $this->getJson('/api/app/me')->assertJsonPath('data.unread_notifications', 2);
        $response = $this->getJson('/api/app/notifications')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'follow')->assertJsonPath('data.0.actor.username', $fan->username);
        $this->assertStringNotContainsString('fan@example.com', $response->getContent());
        $this->assertSame(0, $user->unreadNotifications()->count());
    }

    public function test_search_people_stations_and_dispatches(): void
    {
        $this->signIn();
        $person = User::factory()->create(['username' => 'gogofan', 'email' => 'gogo@example.com']);
        Dispatch::factory()->create(['body' => 'The best restaurants in Shaw this fall']);

        $people = $this->getJson('/api/app/search?tab=people&q=gogo')->assertOk()->assertJsonPath('data.0.username', 'gogofan');
        $this->assertStringNotContainsString('gogo@example.com', $people->getContent());
        $this->getJson('/api/app/search?tab=stations&q=dupont')->assertJsonPath('data.0.code', 'A03');
        $this->getJson('/api/app/search?q=restaurant')->assertJsonCount(1, 'data');
        $this->getJson('/api/app/search?q=x')->assertStatus(422);
    }

    public function test_station_page(): void
    {
        $this->signIn();
        Dispatch::factory()->create(['stop' => 'A01']);

        $this->getJson('/api/app/stations/c01')->assertOk()
            ->assertJsonPath('data.code', 'A01')->assertJsonPath('data.name', 'Metro Center')
            ->assertJsonPath('data.lines', ['red', 'orange', 'blue', 'silver'])
            ->assertJsonCount(1, 'data.dispatches');
        $this->getJson('/api/app/stations/ZZZ')->assertNotFound();
    }

    public function test_quiz_hides_answers_until_you_reach_them(): void
    {
        $this->seed(QuizQuestionSeeder::class);
        $this->signIn();

        $quiz = $this->getJson('/api/app/quiz')->assertOk()->assertJsonCount(5, 'data.questions');
        $this->assertNull($quiz->json('data.questions.0.answer'));

        $after = $this->postJson('/api/app/quiz/answer', ['choice' => 1])->assertOk();
        $this->assertNotNull($after->json('data.questions.0.answer'));
        $this->assertNull($after->json('data.questions.1.answer'));
        $this->assertSame(DailyQuiz::number(DailyQuiz::today()), $after->json('data.number'));
    }

    public function test_messages_follow_the_messaging_rules(): void
    {
        $me = $this->signIn();
        [$friend, $stranger] = User::factory()->count(2)->create();
        $friend->following()->attach($me);

        $this->postJson('/api/app/conversations', ['to' => $stranger->username])->assertJsonValidationErrors('to');
        $id = $this->postJson('/api/app/conversations', ['to' => '@'.$friend->username])->assertCreated()->json('data.id');

        $this->postJson("/api/app/conversations/{$id}/messages", ['body' => 'Pupusas Saturday?'])->assertCreated()->assertJsonPath('data.mine', true);
        $this->getJson('/api/app/conversations')->assertJsonPath('data.0.latest.body', 'Pupusas Saturday?');
        $this->getJson("/api/app/conversations/{$id}")->assertOk()->assertJsonCount(1, 'messages');

        $this->signIn($stranger);
        $this->getJson("/api/app/conversations/{$id}")->assertNotFound();

        $this->signIn($friend);
        $this->getJson('/api/app/me')->assertJsonPath('data.unread_messages', 1);
        $message = Conversation::find($id)->messages()->first();
        $this->postJson("/api/app/messages/{$message->id}/report", ['reason' => 'spam'])->assertOk();
        $this->assertDatabaseCount('message_reports', 1);
    }

    public function test_config_lists_lines_stations_and_allowed_types(): void
    {
        $this->signIn();

        $config = $this->getJson('/api/app/config')->assertOk()
            ->assertJsonPath('data.lines.0.key', 'red')->assertJsonPath('data.lines.0.color', '#bf0d3e')
            ->assertJsonCount(count(config('stoop.stations')), 'data.stations');
        $types = collect($config->json('data.types'))->pluck('key');
        $this->assertNotContains('callout', $types);
        $this->assertContains('safety', $types);
    }

    public function test_endpoints_are_throttled(): void
    {
        $this->signIn();

        foreach (range(1, 15) as $i) {
            $this->getJson('/api/app/search?tab=stations&q=shaw')->assertOk();
        }
        $this->getJson('/api/app/search?tab=stations&q=shaw')->assertStatus(429);
        $this->getJson('/api/app/feed')->assertOk();
    }
}
