<?php

namespace Tests\Feature;

use App\Livewire\Admin\Assist;
use App\Livewire\Admin\Insights;
use App\Livewire\Composer;
use App\Livewire\Pages\Moderation;
use App\Models\Appeal;
use App\Models\Dispatch;
use App\Models\Penalty;
use App\Models\User;
use App\Support\ModerationAssist;
use App\Support\ModerationInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class ModerationAssistTest extends TestCase
{
    use RefreshDatabase;

    /** How many times the fake Claude was asked. */
    protected int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => null, 'services.openai.key' => null]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        ModerationAssist::fake(null);
        parent::tearDown();
    }

    protected function claude(string $reply): void
    {
        $this->calls = 0;
        ModerationAssist::fake(function () use ($reply) {
            $this->calls++;

            return $reply;
        });
    }

    protected function admin(): User
    {
        return Sanctum::actingAs(User::factory()->create(['is_staff' => true]), ['admin']);
    }

    public function test_only_staff_with_an_admin_token_get_in(): void
    {
        $this->getJson('/api/admin/ai/queue')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(), ['admin']);
        $this->getJson('/api/admin/ai/queue')->assertForbidden();
        $this->getJson('/api/admin/ai/insights')->assertForbidden();
        $this->postJson('/api/admin/ai/watch-phrases', ['phrase' => 'x y z', 'meaning' => 'm', 'severity' => 'low'])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['is_staff' => true]), ['dispatch:write']);
        $this->getJson('/api/admin/ai/queue')->assertForbidden();

        $this->actingAs(User::factory()->create())->get('/admin/insights')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_staff' => true]))->get('/admin/insights')->assertOk()->assertSee('east of the river types');
    }

    public function test_the_suggestion_is_asked_once_and_cached(): void
    {
        $this->claude('{"explanation": "Uses a ward as a stand-in for race.", "action": "remove", "rule": "Attacking people for who they are", "penalty": "ban", "confidence": "high"}');
        $held = Dispatch::factory()->create(['status' => 'held']);
        $this->admin();

        $this->getJson('/api/admin/ai/queue')->assertJsonPath('ai', true)->assertJsonPath('items.0.suggestion', null);
        $this->assertSame(0, $this->calls);

        $this->postJson("/api/admin/ai/dispatches/{$held->id}/suggest")
            ->assertJsonPath('suggestion.action', 'remove')
            ->assertJsonPath('suggestion.rule', 'Attacking people for who they are')
            ->assertJsonPath('suggestion.penalty', 'none') // never a ban
            ->assertJsonPath('suggestion.confidence', 'high');
        $this->postJson("/api/admin/ai/dispatches/{$held->id}/suggest")->assertJsonPath('suggestion.action', 'remove');
        $this->getJson('/api/admin/ai/queue')->assertJsonPath('items.0.suggestion.action', 'remove');

        $this->assertSame(1, $this->calls);
        $this->assertSame('held', $held->fresh()->status);
    }

    public function test_an_active_watch_phrase_holds_a_post_and_shows_why(): void
    {
        $author = User::factory()->create();
        Livewire::actingAs($author)->test(Composer::class)->set('body', 'Keep the  Section 8 crowd out of Navy Yard')->call('file')->assertHasNoErrors();
        $dispatch = Dispatch::latest('id')->first();
        $this->assertSame('held', $dispatch->status);
        $this->assertSame('Section 8 crowd', $dispatch->screening['watch'][0]['phrase']);
        $this->assertTrue(ModerationInsights::blur($dispatch));

        // Seeded broad examples start off, so they don't hold anything.
        Livewire::actingAs($author)->test(Composer::class)->set('body', 'Those people at the bakery were lovely')->call('file')->assertHasNoErrors();
        $this->assertSame('approved', Dispatch::latest('id')->first()->status);

        Livewire::actingAs(User::factory()->create(['is_staff' => true]))->test(Moderation::class)
            ->assertSee('Held: Watch phrase “Section 8 crowd”')->assertSee('Click to show');
    }

    public function test_staff_manage_watch_phrases_on_the_web_and_in_the_app(): void
    {
        Livewire::actingAs(User::factory()->create(['is_staff' => true]))->test(Insights::class)
            ->set('phrase', 'certain element')->set('meaning', 'Coded for race.')->set('severity', 'high')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('watch_phrases', ['phrase' => 'certain element', 'active' => true]);

        $this->admin();
        $id = $this->postJson('/api/admin/ai/watch-phrases', ['phrase' => 'gentrifier types', 'meaning' => 'Test.', 'severity' => 'low'])->assertOk()->json('id');
        $this->putJson("/api/admin/ai/watch-phrases/$id", ['phrase' => 'gentrifier types', 'meaning' => 'Test.', 'severity' => 'medium', 'active' => false])->assertOk();
        $this->assertDatabaseHas('watch_phrases', ['id' => $id, 'severity' => 'medium', 'active' => false]);
        $this->postJson('/api/admin/ai/watch-phrases', ['phrase' => 'gentrifier types', 'meaning' => 'Dupe', 'severity' => 'nope'])->assertJsonValidationErrors(['phrase', 'severity']);
        $this->deleteJson("/api/admin/ai/watch-phrases/$id")->assertOk();
        $this->assertDatabaseMissing('watch_phrases', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'watch_phrase.deleted']);
    }

    /** @var array<string, float> */
    protected array $scores = [];

    protected function fakeScores(array $scores): void
    {
        config(['services.openai.key' => 'sk-test', 'stoop.model_moderation' => true]);
        $this->scores = $scores;
        Http::fake(['api.openai.com/v1/moderations' => fn () => Http::response([
            'results' => [['flagged' => false, 'category_scores' => $this->scores + ['hate' => 0.01, 'harassment' => 0.01]]],
        ])]);
    }

    public function test_the_second_pass_on_borderline_posts_can_only_hold(): void
    {
        $author = User::factory()->create();

        // Borderline hate (0.1 is between half the 0.15 threshold and the threshold): Claude looks.
        $this->fakeScores(['hate' => 0.1]);
        $this->claude('{"hold": true, "reason": "Uses the ward as a stand-in for race.", "action": "remove"}');
        Livewire::actingAs($author)->test(Composer::class)->set('body', 'You know what kind of people live in Ward 8')->call('file');
        $held = Dispatch::latest('id')->first();
        $this->assertSame('held', $held->status);
        $this->assertSame(['hate' => 0.1], $held->screening['coded']['scores']);
        $this->assertSame(0, Penalty::count());

        // Even if it says to remove, it can't: anything but hold lets the post through.
        $this->claude('{"hold": false, "remove": true}');
        Livewire::actingAs($author)->test(Composer::class)->set('body', 'Ward 8 has great go-go')->call('file');
        $this->assertSame('approved', Dispatch::latest('id')->first()->status);
        $this->assertSame(0, Penalty::count());

        // Clearly fine scores: Claude isn't asked at all.
        $this->calls = 0;
        $this->fakeScores(['hate' => 0.02]);
        Livewire::actingAs($author)->test(Composer::class)->set('body', 'Half-smokes at the game')->call('file');
        $this->assertSame('approved', Dispatch::latest('id')->first()->status);
        $this->assertSame(0, $this->calls);
    }

    public function test_past_decisions_come_from_similar_moderated_posts(): void
    {
        $moderator = User::factory()->create(['is_staff' => true]);
        $decided = fn (string $body, string $status) => Dispatch::factory()->create(['body' => $body, 'status' => $status, 'moderated_by' => $moderator->id, 'moderation_reason' => $status === 'removed' ? 'Spam or networking pitches' : null]);
        $decided('Crypto investment opportunity, message me', 'removed');
        $decided('Another crypto investment pitch', 'removed');
        $decided('Is crypto worth it for retirement?', 'approved');
        Dispatch::factory()->create(['body' => 'Crypto meetup tonight', 'status' => 'approved']); // nobody moderated it
        $decided('Best tacos in Petworth', 'approved');

        $past = ModerationInsights::pastDecisions(Dispatch::factory()->create(['body' => 'Great crypto investment, DM me', 'status' => 'held']));

        $this->assertSame(1, $past['approved']);
        $this->assertSame(2, $past['removed']);
        $this->assertCount(3, $past['examples']);
        $this->assertStringContainsString('investment', $past['examples'][0]['body']);
    }

    public function test_threshold_math(): void
    {
        $this->assertSame([null, 'Too few decisions to say yet.'], ModerationInsights::suggest(0.35, 3, 1));
        $this->assertSame(0.4, ModerationInsights::suggest(0.35, 9, 1, 0.8)[0]);
        $this->assertNull(ModerationInsights::suggest(0.4, 10, 0, 0.45)[0]); // already next to auto-remove
        $this->assertSame(0.1, ModerationInsights::suggest(0.15, 1, 9, 0.45)[0]);
        $this->assertNull(ModerationInsights::suggest(0.5, 5, 5)[0]);

        $moderator = User::factory()->create(['is_staff' => true]);
        foreach (['approved', 'approved', 'approved', 'approved', 'removed'] as $status) {
            Dispatch::factory()->create(['status' => $status, 'moderated_by' => $moderator->id, 'screening' => ['openai' => ['harassment' => 0.4]]]);
        }
        // Too old, and an automatic removal nobody decided: neither counts.
        Dispatch::factory()->create(['status' => 'removed', 'moderated_by' => $moderator->id, 'screening' => ['openai' => ['harassment' => 0.4]], 'created_at' => now()->subDays(31)]);
        Dispatch::factory()->create(['status' => 'removed', 'screening' => ['openai' => ['harassment' => 0.9], 'auto_removed' => 'harassment']]);

        $row = collect(ModerationInsights::thresholds())->firstWhere('category', 'harassment');
        $this->assertSame(['held' => 5, 'approved' => 4, 'removed' => 1, 'approval_rate' => 0.8, 'suggested' => 0.4], array_intersect_key($row, array_flip(['held', 'approved', 'removed', 'approval_rate', 'suggested'])));

        $this->admin();
        $this->getJson('/api/admin/ai/insights')->assertJsonPath('days', 30)->assertJsonPath('categories.2.category', 'harassment');
    }

    public function test_without_a_key_everything_falls_back_quietly(): void
    {
        $person = User::factory()->create();
        $moderator = User::factory()->create(['is_staff' => true]);
        $removed = Dispatch::factory()->create(['user_id' => $person->id, 'status' => 'removed', 'moderated_by' => $moderator->id]);
        Penalty::create(['user_id' => $person->id, 'subject_hash' => $person->subjectHash(), 'type' => 'warning', 'reason' => 'Threats or harassment', 'moderator_id' => $moderator->id, 'dispatch_id' => $removed->id]);
        $appeal = Appeal::create(['user_id' => $person->id, 'dispatch_id' => $removed->id, 'body' => 'It was a joke', 'status' => 'open']);
        Dispatch::factory()->create(['status' => 'held']);

        $this->admin();
        $this->getJson('/api/admin/ai/queue')->assertJsonPath('ai', false)->assertJsonPath('items.0.suggestion', null);
        $this->postJson("/api/admin/ai/dispatches/{$removed->id}/suggest")->assertStatus(503);
        $this->getJson("/api/admin/ai/appeals/{$appeal->id}")->assertJsonPath('ai', false)->assertJsonPath('notes', null);
        $brief = $this->getJson("/api/admin/ai/users/{$person->id}/brief")->assertJsonPath('ai', false)->json('text');
        $this->assertStringContainsString('1 post, 1 removed', $brief);
        $this->assertStringContainsString('Threats or harassment', $brief);
        $this->assertSame(0, DB::table('moderation_notes')->count());

        Livewire::withoutLazyLoading()->actingAs($moderator)->test(Assist::class, ['kind' => 'dispatch', 'subjectId' => $removed->id])->assertSee('needs ANTHROPIC_API_KEY');
        Livewire::withoutLazyLoading()->actingAs($moderator)->test(Assist::class, ['kind' => 'user', 'subjectId' => $person->id])->assertSee('1 warning');
    }

    public function test_the_brief_and_appeal_notes_use_claude_and_are_cached(): void
    {
        $person = User::factory()->create();
        $removed = Dispatch::factory()->create(['user_id' => $person->id, 'status' => 'removed', 'moderation_reason' => 'Threats or harassment']);
        $appeal = Appeal::create(['user_id' => $person->id, 'dispatch_id' => $removed->id, 'body' => 'I never threatened anyone', 'status' => 'open']);
        $this->admin();

        $this->claude('Two warnings this spring; tone has calmed since June.');
        $this->getJson("/api/admin/ai/users/{$person->id}/brief")->assertJsonPath('ai', true)->assertJsonPath('text', 'Two warnings this spring; tone has calmed since June.');
        $this->getJson("/api/admin/ai/users/{$person->id}/brief");
        $this->assertSame(1, $this->calls);

        $this->claude('```json
{"summary": "The post reads as a threat.", "supports": [], "contradicts": ["The post says \"you will regret it\"."]}
```');
        $this->getJson("/api/admin/ai/appeals/{$appeal->id}")->assertJsonPath('notes.contradicts.0', 'The post says "you will regret it".');
        $this->getJson("/api/admin/ai/appeals/{$appeal->id}")->assertJsonPath('notes.summary', 'The post reads as a threat.');
        $this->assertSame(1, $this->calls);

        Livewire::withoutLazyLoading()->actingAs(User::factory()->create(['is_staff' => true]))->test(Assist::class, ['kind' => 'appeal', 'subjectId' => $appeal->id])
            ->assertSee('Contradicts the appeal')->assertSee('you will regret it');
    }
}
