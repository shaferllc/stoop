<?php

namespace Tests\Feature;

use App\Livewire\Composer;
use App\Livewire\Pages\Moderation;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\ModerationModel;
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
        $this->fakeScores(['hate' => 0.91, 'harassment' => 0.72]);

        Livewire::actingAs(User::factory()->create())->test(Composer::class)
            ->set('body', 'something hateful')->call('file')->assertHasNoErrors();

        $dispatch = Dispatch::first();
        $this->assertSame('held', $dispatch->status);
        $this->assertSame(['hate' => 0.91, 'harassment' => 0.72], $dispatch->screening['openai']);

        Livewire::actingAs(User::factory()->create(['is_staff' => true]))->test(Moderation::class)
            ->assertSee('Held by the automatic check')->assertSee('hate 0.91');
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
}
