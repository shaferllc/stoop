<?php

namespace Tests\Feature;

use App\Livewire\Composer;
use App\Livewire\Pages\AppealCreate;
use App\Livewire\Pages\Moderation;
use App\Models\Dispatch;
use App\Models\User;
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
        $this->fakeScores(['hate' => 0.71, 'harassment' => 0.72]);

        Livewire::actingAs(User::factory()->create())->test(Composer::class)
            ->set('body', 'something hateful')->call('file')->assertHasNoErrors();

        $dispatch = Dispatch::first();
        $this->assertSame('held', $dispatch->status);
        $this->assertSame(['harassment' => 0.72, 'hate' => 0.71], $dispatch->screening['openai']);

        Livewire::actingAs(User::factory()->create(['is_staff' => true]))->test(Moderation::class)
            ->assertSee('Held by the automatic check')->assertSee('hate 0.71');
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

    public function test_staff_posts_skip_the_check(): void
    {
        Http::fake();

        Livewire::actingAs(User::factory()->create(['is_staff' => true]))->test(Composer::class)->set('body', 'Staff update')->call('file');

        $this->assertSame('approved', Dispatch::first()->status);
        Http::assertNothingSent();
    }

    public function test_clear_hate_is_removed_automatically_and_strikes_climb_the_ladder(): void
    {
        $this->fakeScores(['hate' => 0.95]);
        $user = User::factory()->create(['created_at' => now()->subMonth()]);

        $file = function () use ($user) {
            // Lift any timeout from the last strike so the next post can be filed.
            $user->penalties()->where('type', 'timeout')->update(['ends_at' => now()->subMinute()]);
            Livewire::actingAs($user)->test(Composer::class)->set('body', 'hateful post')->call('file')->assertHasNoErrors();

            return Dispatch::latest('id')->first();
        };

        $first = $file();
        $this->assertSame('removed', $first->status);
        $this->assertSame('Attacking people for who they are', $first->moderation_reason);
        $this->assertSame('warning', $user->penalties()->latest('id')->first()->type);
        $this->assertStringContainsString('This is a warning', $user->notifications()->first()->data['message']);

        $file();
        $this->assertSame(24, (int) round(now()->diffInHours($user->penalties()->latest('id')->first()->ends_at)));
        $file();
        $this->assertSame(168, (int) round(now()->diffInHours($user->penalties()->latest('id')->first()->ends_at)));
        $file();
        $this->assertSame(720, (int) round(now()->diffInHours($user->penalties()->latest('id')->first()->ends_at)));

        $this->assertNull($user->activeBan(), 'Bans are never automatic.');
        $this->assertTrue(Strikes::banCandidates()->contains('id', $user->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'ban.recommended']);

        // The author can appeal an automatic removal, and any moderator can decide it.
        Livewire::actingAs($user)->test(AppealCreate::class, ['dispatch' => $first->id])
            ->set('body', 'I was quoting someone to report them.')->call('submit');
        Livewire::actingAs(User::factory()->create(['is_staff' => true]))->test(Moderation::class)
            ->set('view', 'appeals')->assertSee('I was quoting someone');
    }

    public function test_borderline_posts_are_held_not_removed(): void
    {
        $this->fakeScores(['hate' => 0.6]);

        Livewire::actingAs(User::factory()->create())->test(Composer::class)->set('body', 'borderline')->call('file');

        $this->assertSame('held', Dispatch::first()->status);
        $this->assertDatabaseCount('penalties', 0);
    }
}
