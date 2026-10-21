<?php

namespace Tests\Feature;

use App\Actions\FileDispatch;
use App\Actions\Moderate;
use App\Livewire\Admin\AccountPanel;
use App\Livewire\Admin\Activity;
use App\Livewire\Admin\Bots;
use App\Livewire\Admin\Content;
use App\Livewire\Admin\Roles;
use App\Livewire\Admin\Spam;
use App\Livewire\Admin\Sponsorships;
use App\Livewire\Admin\Users;
use App\Livewire\Pages\Moderation;
use App\Models\Appeal;
use App\Models\Dispatch;
use App\Models\Penalty;
use App\Models\SpamCluster;
use App\Models\User;
use App\Notifications\ModerationMail;
use App\Support\AccountCheck;
use App\Support\Bots as HouseBots;
use App\Support\SignupRisk;
use App\Support\SpamRings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminOpsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        AccountCheck::fake(null);
        parent::tearDown();
    }

    protected function staff(string ...$roles): User
    {
        return User::factory()->create(['is_staff' => true, 'roles' => $roles ?: null]);
    }

    protected function newbie(array $attributes = []): User
    {
        return User::factory()->create(['created_at' => now()->subDay(), ...$attributes]);
    }

    // Spam rings...

    public function test_near_identical_posts_from_different_accounts_form_a_cluster(): void
    {
        $text = 'Best deal in town, message me for cheap Capitals tickets tonight at the arena';
        foreach (['Best deal in town! Message me for cheap Capitals tickets tonight at the arena.', $text, strtoupper($text)] as $body) {
            Dispatch::factory()->create(['body' => $body]);
        }
        Dispatch::factory()->count(2)->create(['body' => 'same here']);
        Dispatch::factory()->create(['body' => 'A completely different post about half-smokes on U Street this weekend']);
        Dispatch::factory()->create(['body' => $text, 'user_id' => $this->staff()->id]);

        SpamRings::duplicates();

        $cluster = SpamCluster::sole();
        $this->assertSame('duplicate', $cluster->kind);
        $this->assertCount(3, $cluster->members);
        $this->assertCount(3, $cluster->evidence);

        // Running again updates the same cluster instead of adding another.
        SpamRings::detect();
        $this->assertSame(1, SpamCluster::count());
    }

    public function test_a_burst_of_new_accounts_in_one_thread(): void
    {
        $thread = Dispatch::factory()->create();
        foreach (range(1, 4) as $i) {
            Dispatch::factory()->create(['parent_id' => $thread->id, 'user_id' => $this->newbie()->id, 'body' => "reply number $i"]);
        }
        // Older accounts don't count.
        Dispatch::factory()->create(['parent_id' => Dispatch::factory()->create()->id, 'body' => 'hi']);

        SpamRings::threadBursts();

        $cluster = SpamCluster::sole();
        $this->assertSame('thread:'.$thread->id, $cluster->signature);
        $this->assertCount(4, $cluster->members);
    }

    public function test_the_same_link_domain_from_several_new_accounts(): void
    {
        foreach (['Check https://cheap-pills.example/x', 'Deals at www.cheap-pills.example', 'https://CHEAP-PILLS.example/y wow'] as $body) {
            Dispatch::factory()->create(['user_id' => $this->newbie()->id, 'body' => $body]);
        }
        foreach (User::factory()->count(3)->create(['created_at' => now()->subMonth()]) as $old) {
            Dispatch::factory()->create(['user_id' => $old->id, 'body' => 'old accounts can link https://wtop.example/news']);
        }

        SpamRings::linkSpam();

        $this->assertSame('link:cheap-pills.example', SpamCluster::sole()->signature);
    }

    public function test_new_accounts_messaging_many_people(): void
    {
        $spammer = $this->newbie();
        $quiet = $this->newbie();
        foreach (User::factory()->count(10)->create() as $person) {
            $conversation = DB::table('conversations')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
            DB::table('conversation_participants')->insert([['conversation_id' => $conversation, 'user_id' => $spammer->id], ['conversation_id' => $conversation, 'user_id' => $person->id]]);
            DB::table('messages')->insert(['conversation_id' => $conversation, 'user_id' => $spammer->id, 'body' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        }

        SpamRings::messageBlasts();

        $cluster = SpamCluster::sole();
        $this->assertSame('dm_blast', $cluster->kind);
        $this->assertSame([$spammer->id], $cluster->members);
        $this->assertNotContains($quiet->id, $cluster->members);
    }

    public function test_acting_on_a_cluster_hides_posts_and_times_out_but_never_bans(): void
    {
        $moderator = $this->staff('moderator');
        $a = $this->newbie();
        $b = $this->newbie();
        $house = $this->staff();
        $postA = Dispatch::factory()->create(['user_id' => $a->id]);
        $postB = Dispatch::factory()->create(['user_id' => $b->id, 'status' => 'pending']);
        $staffPost = Dispatch::factory()->create(['user_id' => $house->id]);
        $cluster = SpamCluster::create(['kind' => 'link', 'signature' => 'link:x', 'summary' => 'x', 'members' => [$a->id, $b->id, $house->id], 'evidence' => []]);

        Livewire::actingAs($moderator)->test(Spam::class)->assertSee('x')->set('hours', 72)->call('act', $cluster->id);

        $this->assertSame('hidden', $postA->fresh()->status);
        $this->assertSame('hidden', $postB->fresh()->status);
        $this->assertSame('approved', $staffPost->fresh()->status);
        $this->assertSame(['timeout', 'timeout'], Penalty::pluck('type')->all());
        $this->assertSame(0, Penalty::where('type', 'ban')->count());
        $this->assertTrue($a->activeTimeout()->ends_at->gt(now()->addHours(71)));
        $this->assertSame('actioned', $cluster->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'spam_cluster.actioned']);

        // Handled clusters stay handled.
        $this->expectException(HttpException::class);
        SpamRings::act($cluster->fresh(), $moderator);
    }

    public function test_the_spam_api_lists_and_acts(): void
    {
        Sanctum::actingAs($this->staff('moderator'), ['admin']);
        $member = $this->newbie();
        $cluster = SpamCluster::create(['kind' => 'duplicate', 'signature' => 'd', 'summary' => '3 accounts', 'members' => [$member->id], 'evidence' => []]);

        $this->getJson('/api/admin/spam-clusters')->assertOk()->assertJsonPath('0.members.0.username', $member->username)->assertJsonMissingPath('0.members.0.email');
        $this->postJson('/api/admin/spam-clusters/detect')->assertOk();
        $this->postJson("/api/admin/spam-clusters/{$cluster->id}/act", ['hours' => 24])->assertOk()->assertJsonPath('timeouts', 1);
        $this->postJson("/api/admin/spam-clusters/{$cluster->id}/dismiss")->assertOk();
        $this->assertSame('dismissed', $cluster->fresh()->status);
    }

    // Sign-up risk...

    public function test_risky_sign_ups_get_a_score_and_their_first_posts_are_held(): void
    {
        foreach (['one', 'two'] as $name) {
            $this->post(route('register.store'), ['name' => $name, 'username' => "early$name", 'email' => "$name@example.com", 'password' => 'password', 'password_confirmation' => 'password']);
            auth()->logout();
        }
        $this->post(route('register.store'), ['name' => 'Spam', 'username' => 'xkqzt83920', 'email' => 'spam@mailinator.com', 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertSessionHasNoErrors();

        $user = User::where('username', 'xkqzt83920')->first();
        $this->assertSame(90, $user->risk_score);
        $this->assertCount(3, $user->risk_reasons);
        $this->assertSame(64, strlen($user->signup_ip_hash));
        $this->assertNotSame('127.0.0.1', $user->signup_ip_hash);
        $this->assertArrayNotHasKey('risk_score', $user->toArray());

        $user->forceFill(['accepted_rules_at' => now()])->save();
        $this->assertSame('held', FileDispatch::handle($user, ['body' => 'My first dispatch'])->status);

        // After a few approved posts they post normally.
        Dispatch::factory()->count(SignupRisk::HELD_POSTS)->create(['user_id' => $user->id]);
        $this->assertSame('approved', FileDispatch::handle($user->fresh(), ['body' => 'Now I am trusted'])->status);

        $calm = User::where('username', 'earlyone')->first();
        $this->assertSame(0, $calm->risk_score);
        $this->assertTrue(SignupRisk::looksRandom('qwrtzp'));
        $this->assertFalse(SignupRisk::looksRandom('maria_dc'));
    }

    public function test_risk_is_shown_to_staff_only(): void
    {
        $risky = $this->newbie(['risk_score' => 70, 'risk_reasons' => ['Throwaway email domain']]);

        Livewire::actingAs($this->staff('moderator'))->test(AccountPanel::class, ['userId' => $risky->id])
            ->assertSee('Throwaway email domain')->assertSee('FIRST POSTS HELD')->assertSee('Needs ANTHROPIC_API_KEY');
        Livewire::actingAs($this->newbie())->test(AccountPanel::class, ['userId' => $risky->id])->assertForbidden();
        $this->get(route('users.show', $risky))->assertDontSee('Throwaway email domain');
    }

    // Claude account check...

    public function test_account_check_falls_back_to_heuristics_without_a_key(): void
    {
        config(['services.anthropic.key' => null]);
        $spammer = $this->newbie(['risk_score' => 60, 'risk_reasons' => ['Throwaway email domain', 'Several sign-ups from the same network in an hour']]);
        Dispatch::factory()->count(4)->create(['user_id' => $spammer->id, 'body' => 'Buy now at https://spam.example today']);

        $result = AccountCheck::run($spammer);
        $this->assertSame('heuristic', $result['source']);
        $this->assertSame('bot', $result['verdict']);
        $this->assertContains('Posts the same text over and over', $result['reasons']);

        $this->assertSame('human', AccountCheck::run(User::factory()->create())['verdict']);

        SpamCluster::create(['kind' => 'link', 'signature' => 's', 'summary' => 'Same link', 'members' => [$spammer->id], 'evidence' => []]);
        $this->assertSame('spam_ring', AccountCheck::run($spammer)['verdict']);
    }

    public function test_account_check_uses_claude_when_available(): void
    {
        $user = $this->newbie();
        Dispatch::factory()->create(['user_id' => $user->id, 'body' => 'hello neighbors']);
        $seen = null;
        AccountCheck::fake(function ($activity) use (&$seen) {
            $seen = $activity;

            return 'Here you go: {"verdict": "spam_ring", "confidence": 82, "reasons": ["Same text as five other accounts"]}';
        });

        Sanctum::actingAs($this->staff('moderator'), ['admin']);
        $this->postJson("/api/admin/users/{$user->id}/check")->assertOk()
            ->assertJsonPath('verdict', 'spam_ring')->assertJsonPath('confidence', 82)->assertJsonPath('source', 'claude');
        $this->assertStringContainsString('hello neighbors', $seen);
        $this->assertStringNotContainsString($user->email, $seen);
        $this->assertSame(0, Penalty::count());

        // A reply Claude can't be parsed from falls back.
        AccountCheck::fake(fn () => 'I am not sure.');
        $this->assertSame('heuristic', AccountCheck::run($user)['source']);
    }

    // House bots...

    public function test_bot_console_marks_answers_wrong_and_posts_corrections(): void
    {
        $asker = User::factory()->create();
        $question = Dispatch::factory()->create(['user_id' => $asker->id, 'body' => '@stoop how long can a dispatch be?']);
        $answer = Dispatch::factory()->create(['user_id' => HouseBots::account('stoop')->id, 'parent_id' => $question->id, 'body' => 'Up to 50 characters.']);
        $editor = $this->staff('editor');

        Livewire::actingAs($editor)->test(Bots::class)
            ->assertSee('Up to 50 characters.')->assertSee($question->body)
            ->call('markWrong', $answer->id)
            ->set('correcting', $answer->id)->set('correction', 'Correction: a dispatch can be up to 500 characters.')->call('correct')
            ->assertHasNoErrors();

        $this->assertNotEmpty($answer->fresh()->details['marked_wrong_at']);
        $correction = Dispatch::where('parent_id', $answer->id)->sole();
        $this->assertSame('stoop', $correction->user->username);

        // Only bot answers can be corrected.
        Sanctum::actingAs($editor, ['admin']);
        $this->postJson("/api/admin/bots/answers/{$question->id}/correct", ['body' => 'nope nope'])->assertNotFound();
        $this->getJson('/api/admin/bots/answers')->assertJsonPath('0.wrong', false)->assertJsonPath('1.wrong', true);
    }

    public function test_knowledge_base_entries_are_editable_and_seeded(): void
    {
        $this->assertGreaterThan(10, DB::table('bot_knowledge')->count());
        $this->assertStringContainsString('500 characters', HouseBots::answer('@stoop how many characters can a dispatch be?'));

        Livewire::actingAs($this->staff('editor'))->test(Bots::class)
            ->set('section', 'Parking')->set('body', 'Stoop has no parking validation, sorry about the meters.')->call('save')->assertHasNoErrors();
        $this->assertStringContainsString('parking validation', HouseBots::answer('@stoop do you validate parking at the meters?'));

        Sanctum::actingAs($this->staff('editor'), ['admin']);
        $id = DB::table('bot_knowledge')->where('section', 'Parking')->value('id');
        $this->postJson('/api/admin/bots/knowledge', ['id' => $id, 'section' => 'Parking', 'body' => 'Meters are on you.'])->assertOk();
        $this->deleteJson("/api/admin/bots/knowledge/$id")->assertOk();
        $this->assertDatabaseMissing('bot_knowledge', ['section' => 'Parking']);
    }

    // Activity...

    public function test_activity_feed_and_daily_counts(): void
    {
        $post = Dispatch::factory()->create(['body' => 'Fresh post on the Red Line']);
        Dispatch::factory()->create(['status' => 'removed', 'moderation_reason' => 'Spam']);
        Dispatch::factory()->create(['created_at' => now()->subDays(3)]);

        Livewire::actingAs($this->staff('sales'))->test(Activity::class)->assertSee('Fresh post on the Red Line')->assertSee('Removed: Spam');

        Sanctum::actingAs($this->staff('sales'), ['admin']);
        $days = $this->getJson('/api/admin/activity')->assertOk()->assertJsonCount(30, 'days')->json('days');
        $this->assertSame(2, end($days)['posts']);
        $this->assertSame(1, end($days)['removals']);
        $this->assertSame(1, $days[26]['posts']);
    }

    // People...

    public function test_bulk_actions_in_people(): void
    {
        $moderator = $this->staff('moderator');
        [$a, $b] = User::factory()->count(2)->unverified()->create();
        $staff = $this->staff();

        Livewire::actingAs($moderator)->test(Users::class)
            ->set('selected', [(string) $a->id, (string) $b->id])->call('bulk', 'verify')
            ->set('selected', [(string) $a->id, (string) $staff->id])->set('bulkHours', '24')->call('bulk', 'timeout')
            ->set('selected', [(string) $b->id])->set('bulkNote', 'Watch this one')->call('bulk', 'note');

        $this->assertNotNull($a->fresh()->email_verified_at);
        $this->assertNotNull($a->activeTimeout());
        $this->assertNull($staff->activeTimeout());
        $this->assertDatabaseHas('user_notes', ['user_id' => $b->id, 'body' => 'Watch this one']);

        Sanctum::actingAs($moderator, ['admin']);
        $this->postJson('/api/admin/users/bulk', ['ids' => [$b->id], 'action' => 'timeout', 'hours' => 1, 'reason' => config('stoop.rule_reasons.4')])->assertJsonPath('count', 1);
        $this->postJson('/api/admin/users/bulk', ['ids' => [$b->id], 'action' => 'timeout'])->assertJsonValidationErrors(['hours', 'reason']);
    }

    // Roles...

    public function test_roles_gate_the_web_admin(): void
    {
        $editor = $this->staff('editor');
        Livewire::actingAs($editor)->test(Content::class)->assertOk();
        Livewire::actingAs($editor)->test(Users::class)->assertForbidden();
        Livewire::actingAs($editor)->test(Moderation::class)->assertForbidden();
        Livewire::actingAs($editor)->test(Sponsorships::class)->assertForbidden();
        Livewire::actingAs($editor)->test(Roles::class)->assertForbidden();

        $moderator = $this->staff('moderator');
        Livewire::actingAs($moderator)->test(Moderation::class)->assertOk();
        Livewire::actingAs($moderator)->test(Spam::class)->assertOk();
        Livewire::actingAs($moderator)->test(Content::class)->assertForbidden();
        $this->actingAs($moderator)->get(route('admin'))->assertOk()->assertSee('People')->assertDontSee('Sponsorships');

        Livewire::actingAs($this->staff('sales'))->test(Sponsorships::class)->assertOk();

        // Admins do everything, including legacy staff with no roles set.
        foreach ([$this->staff('admin'), $this->staff()] as $admin) {
            Livewire::actingAs($admin)->test(Content::class)->assertOk();
            Livewire::actingAs($admin)->test(Roles::class)->assertOk();
        }
    }

    public function test_admins_set_roles(): void
    {
        $admin = $this->staff('admin');
        $person = User::factory()->create();

        Livewire::actingAs($admin)->test(Roles::class)->set('username', '@'.$person->username)->set('roles', ['editor', 'sales'])->call('save')->assertHasNoErrors();
        $person->refresh();
        $this->assertTrue($person->is_staff);
        $this->assertTrue($person->hasRole('sales'));
        $this->assertFalse($person->hasRole('moderator'));

        // No roles, no staff.
        Livewire::actingAs($admin)->test(Roles::class)->set('username', $person->username)->set('roles', [])->call('save');
        $this->assertFalse($person->fresh()->is_staff);

        // Admins can't lock themselves out.
        Sanctum::actingAs($admin, ['admin']);
        $this->postJson('/api/admin/roles', ['username' => $admin->username, 'roles' => ['editor']])->assertStatus(422);
        $this->postJson('/api/admin/roles', ['username' => $person->username, 'roles' => ['moderator']])->assertOk();
        $this->getJson('/api/admin/roles')->assertJsonFragment(['username' => $person->username, 'roles' => ['moderator']]);
    }

    public function test_roles_gate_the_admin_api(): void
    {
        Sanctum::actingAs($this->staff('editor'), ['admin']);
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('roles', ['editor']);
        $this->getJson('/api/admin/queue')->assertForbidden();
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->getJson('/api/admin/health')->assertForbidden();
        $this->getJson('/api/admin/bots/answers')->assertOk();
        $this->getJson('/api/admin/activity')->assertOk();

        Sanctum::actingAs($this->staff('moderator'), ['admin']);
        $this->getJson('/api/admin/queue')->assertOk();
        $this->getJson('/api/admin/spam-clusters')->assertOk();
        $this->getJson('/api/admin/bots/knowledge')->assertForbidden();
        $this->getJson('/api/admin/roles')->assertForbidden();
        $this->postJson('/api/admin/maintenance/cache')->assertForbidden();

        Sanctum::actingAs($this->staff(), ['admin']);
        $this->getJson('/api/admin/me')->assertJsonPath('roles', ['moderator', 'editor', 'sales', 'admin']);
        $this->getJson('/api/admin/roles')->assertOk();

        Sanctum::actingAs(User::factory()->create(['roles' => ['admin']]), ['admin']);
        $this->getJson('/api/admin/me')->assertForbidden();
    }

    public function test_existing_staff_become_admins(): void
    {
        $migration = require database_path('migrations/2026_10_21_200000_add_admin_ops.php');
        $staff = $this->staff();
        DB::table('users')->where('id', $staff->id)->update(['roles' => null]);
        $migration->down();
        $migration->up();

        $this->assertSame(['admin'], $staff->fresh()->roles);
    }

    // Email...

    public function test_timeouts_bans_and_appeal_decisions_are_emailed(): void
    {
        Notification::fake();
        $moderator = $this->staff('moderator');
        $person = User::factory()->create();
        $quiet = User::factory()->create(['moderation_emails' => false]);

        Moderate::penalize($person, 'warning', 'Spam or networking pitches', null, $moderator);
        Notification::assertNotSentTo($person, ModerationMail::class);

        $timeout = Moderate::penalize($person, '24', 'Spam or networking pitches', null, $moderator);
        Notification::assertSentTo($person, ModerationMail::class, fn ($n, $channels) => $channels === ['mail'] && str_contains($n->lines[0], 'timeout for 1 day'));

        Moderate::penalize($person, 'ban', 'Spam or networking pitches', null, $moderator);
        Notification::assertSentTo($person, ModerationMail::class, fn ($n) => $n->subject === 'Your Stoop account was banned');

        Moderate::penalize($quiet, 'ban', 'Spam or networking pitches', null, $moderator);
        Notification::assertNotSentTo($quiet, ModerationMail::class);

        $appeal = Appeal::create(['user_id' => $person->id, 'penalty_id' => $timeout->id, 'body' => 'Sorry', 'status' => 'open']);
        Moderate::decide($appeal->load(['penalty', 'dispatch', 'user']), $this->staff('moderator'), true);
        Notification::assertSentTo($person, ModerationMail::class, fn ($n) => $n->subject === 'Your Stoop appeal was decided');

        $mail = (new ModerationMail('Subject', ['Line one']))->toMail($person);
        $this->assertSame('Subject', $mail->subject);
        $this->assertStringContainsString('Settings → Safety', implode(' ', $mail->outroLines));
    }

    public function test_daily_digest_goes_to_admins_when_items_wait_over_a_day(): void
    {
        Notification::fake();
        $admin = $this->staff('admin');
        $legacy = $this->staff();
        $editor = $this->staff('editor');

        $this->artisan('stoop:admin-digest')->assertSuccessful();
        Notification::assertNothingSent();

        Dispatch::factory()->create(['status' => 'held', 'updated_at' => now()->subDays(2)]);
        Dispatch::factory()->create(['status' => 'held']);
        $this->artisan('stoop:admin-digest')->assertSuccessful();

        Notification::assertSentTo([$admin, $legacy], ModerationMail::class, fn ($n) => in_array('- 1 posts in the moderation queue', $n->lines, true));
        Notification::assertNotSentTo($editor, ModerationMail::class);
    }
}
