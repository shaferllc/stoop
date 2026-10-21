<?php

namespace Tests\Feature;

use App\Actions\FileDispatch;
use App\Livewire\Composer;
use App\Livewire\Pages\AppealCreate;
use App\Livewire\Pages\Moderation;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\Magazine;
use App\Support\ModerationModel;
use App\Support\Strikes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class OpenAiModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => 'sk-test', 'services.anthropic.key' => null]);
    }

    protected function tearDown(): void
    {
        ModerationModel::fake(null);
        parent::tearDown();
    }

    protected function fakeScores(array $scores): void
    {
        Http::fake(['api.openai.com/v1/moderations' => Http::response([
            'results' => [['flagged' => false, 'category_scores' => $scores + ['hate' => 0.01, 'harassment' => 0.01]]],
        ])]);
    }

    public function test_hate_speech_is_held_with_its_scores(): void
    {
        $this->fakeScores(['hate' => 0.3, 'harassment' => 0.5]);

        Livewire::actingAs(User::factory()->create())->test(Composer::class)
            ->set('body', 'something hateful')->call('file')->assertHasNoErrors();

        $dispatch = Dispatch::first();
        $this->assertSame('held', $dispatch->status);
        $this->assertSame(['harassment' => 0.5, 'hate' => 0.3], $dispatch->screening['openai']);

        Livewire::actingAs(User::factory()->create(['is_staff' => true]))->test(Moderation::class)
            ->assertSee('Held by the automatic check')->assertSee('hate 0.30');
    }

    public function test_clean_posts_go_up_and_claude_still_checks_house_rules(): void
    {
        $this->fakeScores([]);

        Livewire::actingAs(User::factory()->create())->test(Composer::class)->set('body', 'Best bagel in Petworth?')->call('file');
        $this->assertSame('approved', Dispatch::first()->status);

        ModerationModel::fake(fn () => 'hold');
        Livewire::actingAs(User::factory()->create())->test(Composer::class)->set('body', 'Vote for my candidate on Tuesday')->call('file');
        $held = Dispatch::latest('id')->first();
        $this->assertSame('held', $held->status);
        $this->assertSame('hold', $held->screening['claude']);
    }

    public function test_photos_are_sent_with_the_text(): void
    {
        Storage::fake('public');
        $this->fakeScores([]);

        Livewire::actingAs(User::factory()->create())->test(Composer::class)
            ->set('body', 'The line at lunch')->set('photos', [UploadedFile::fake()->image('line.jpg', 400, 300)])->call('file');

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer sk-test')
            && $request['model'] === 'omni-moderation-latest'
            && $request['input'][1]['type'] === 'image_url'
            && str_starts_with($request['input'][1]['image_url']['url'], 'data:image/webp;base64,'));
    }

    public function test_when_openai_is_down_the_post_waits_instead_of_going_up(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('', 503)]);

        Livewire::actingAs(User::factory()->create())->test(Composer::class)->set('body', 'Hello neighbors')->call('file')->assertHasNoErrors();

        $this->assertSame('pending', Dispatch::first()->status);
    }

    public function test_staff_posts_are_checked_and_house_accounts_are_not(): void
    {
        $this->fakeScores(['harassment' => 0.6]);

        Livewire::actingAs(User::factory()->create(['is_staff' => true]))->test(Composer::class)->set('body', 'you are the worst')->call('file');
        $this->assertSame('held', Dispatch::first()->status);

        FileDispatch::handle(Magazine::account(), ['body' => 'New article https://magazine.example/a']);
        $this->assertSame('approved', Dispatch::latest('id')->first()->status);
        Http::assertSentCount(1);
    }

    protected function fileAs(User $user, string $body = 'post'): Dispatch
    {
        // Lift the timeout from the last strike so the next post can be filed.
        $user->penalties()->where('type', 'timeout')->update(['ends_at' => now()->subMinute()]);
        Livewire::actingAs($user)->test(Composer::class)->set('body', $body)->call('file')->assertHasNoErrors();

        return Dispatch::latest('id')->first();
    }

    protected function lastPenaltyHours(User $user): ?int
    {
        $penalty = $user->penalties()->latest('id')->first();

        return $penalty->ends_at ? (int) round(now()->diffInHours($penalty->ends_at)) : null;
    }

    public function test_hate_speech_is_removed_with_no_warning_and_a_second_strike_flags_a_ban(): void
    {
        $this->fakeScores(['hate' => 0.5]);
        $user = User::factory()->create(['created_at' => now()->subMonth()]);

        $first = $this->fileAs($user);
        $this->assertSame('removed', $first->status);
        $this->assertSame('Attacking people for who they are', $first->moderation_reason);
        $this->assertSame(168, $this->lastPenaltyHours($user), 'First hate strike is a 7-day timeout, not a warning.');
        $this->assertStringContainsString('zero tolerance for hate speech', $user->notifications()->first()->data['message']);

        $this->fileAs($user);
        $this->assertSame(720, $this->lastPenaltyHours($user));
        $this->assertNull($user->activeBan(), 'Bans are never automatic.');
        $this->assertTrue(Strikes::banCandidates()->contains('id', $user->id));

        // The author can still appeal, and any moderator can decide it.
        Livewire::actingAs($user)->test(AppealCreate::class, ['dispatch' => $first->id])
            ->set('body', 'I was quoting someone to report them.')->call('submit');
        Livewire::actingAs(User::factory()->create(['is_staff' => true]))->test(Moderation::class)
            ->set('view', 'appeals')->assertSee('I was quoting someone');
    }

    public function test_other_removals_climb_the_regular_ladder(): void
    {
        $this->fakeScores(['harassment' => 0.85]);
        $user = User::factory()->create(['created_at' => now()->subMonth()]);

        $this->assertSame('removed', $this->fileAs($user)->status);
        $this->assertSame('warning', $user->penalties()->latest('id')->first()->type);
        $this->fileAs($user);
        $this->assertSame(24, $this->lastPenaltyHours($user));
        $this->fileAs($user);
        $this->assertSame(168, $this->lastPenaltyHours($user));
        $this->fileAs($user);
        $this->assertSame(720, $this->lastPenaltyHours($user));
        $this->assertTrue(Strikes::banCandidates()->contains('id', $user->id));
    }

    public function test_borderline_posts_are_held_not_removed(): void
    {
        $this->fakeScores(['hate' => 0.3]);

        Livewire::actingAs(User::factory()->create())->test(Composer::class)->set('body', 'borderline')->call('file');

        $this->assertSame('held', Dispatch::first()->status);
        $this->assertDatabaseCount('penalties', 0);
    }
}
