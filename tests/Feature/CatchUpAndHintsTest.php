<?php

namespace Tests\Feature;

use App\Livewire\CatchUp;
use App\Livewire\Composer;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\CatchUp as Digest;
use App\Support\Claude;
use App\Support\ComposeHints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class CatchUpAndHintsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.anthropic.key' => null, 'stoop.model_moderation' => false, 'services.openai.key' => null]);
    }

    protected function tearDown(): void
    {
        Claude::fake(null);
        parent::tearDown();
    }

    public function test_without_a_key_catch_up_lists_the_top_approved_posts(): void
    {
        Dispatch::factory()->create(['stop' => 'A03', 'body' => 'Farmers market is packed']);
        Dispatch::factory()->create(['stop' => 'A03', 'body' => 'Held post about a neighbor', 'status' => 'held']);
        Dispatch::factory()->create(['stop' => 'A03', 'body' => 'Removed post', 'status' => 'removed']);
        Dispatch::factory()->create(['stop' => 'A03', 'body' => 'Old news', 'created_at' => now()->subDays(2)]);
        Dispatch::factory()->create(['stop' => 'E04', 'body' => 'Somewhere else']);

        Livewire::test(CatchUp::class, ['stop' => 'A03'])->call('catchUp')
            ->assertSee('Farmers market is packed')->assertSee('Summaries need ANTHROPIC_API_KEY')
            ->assertDontSee('Held post')->assertDontSee('Removed post')->assertDontSee('Old news')->assertDontSee('Somewhere else');

        $this->get('/?stop=A03')->assertSee('Catch me up');
    }

    public function test_claude_summaries_are_cached_and_rebuilt_when_a_post_is_removed(): void
    {
        $calls = [];
        Claude::fake(function ($system, $user) use (&$calls) {
            $calls[] = $user;

            return "- People are talking about the market\n- And a <b>new</b> bakery";
        });
        $market = Dispatch::factory()->create(['line' => 'red', 'body' => 'Farmers market is packed']);
        Dispatch::factory()->create(['line' => 'red', 'body' => 'New bakery on 14th']);
        Dispatch::factory()->create(['line' => 'red', 'body' => 'Held one', 'status' => 'held']);

        $digest = Digest::for(null, 'red');
        $this->assertSame(['People are talking about the market', 'And a <b>new</b> bakery'], $digest['bullets']);
        Digest::for(null, 'red');
        $this->assertCount(1, $calls, 'Cached for 30 minutes.');
        $this->assertStringContainsString('Farmers market', $calls[0]);
        $this->assertStringNotContainsString('Held one', $calls[0]);

        $market->update(['status' => 'removed']);
        Digest::for(null, 'red');
        $this->assertCount(2, $calls, 'A removed post drops out of the summary.');
        $this->assertStringNotContainsString('Farmers market', $calls[1]);

        Livewire::test(CatchUp::class, ['line' => 'red'])->call('catchUp')
            ->assertSee('And a &lt;b&gt;new&lt;/b&gt; bakery', false);
    }

    public function test_the_app_can_catch_up_too(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['app']);
        Dispatch::factory()->create(['stop' => 'A03', 'body' => 'Farmers market is packed']);

        $this->getJson('/api/app/catch-up')->assertStatus(422);
        $this->getJson('/api/app/catch-up?stop=A03')->assertOk()
            ->assertJsonPath('data.ai', false)->assertJsonPath('data.posts.0.body', 'Farmers market is packed');
    }

    public function test_keyword_guesses_for_topic_type_and_station(): void
    {
        $this->assertSame('green', ComposeHints::guess('Rent went up again on my lease')['line']);
        $this->assertSame('red', ComposeHints::guess('Best pupusas I have had all year')['line']);
        $this->assertSame(['line' => 'yellow', 'type' => 'ask', 'stop' => 'E04'], ComposeHints::guess('Anyone know a good plumber near Columbia Heights?'));
        $this->assertSame('B01', ComposeHints::guess('Chinatown is busy tonight')['stop']);
        $this->assertSame('A02', ComposeHints::guess('Line out the door at Farragut North')['stop']);
        $this->assertSame('E01', ComposeHints::guess('Mount Vernon Square has a new mural')['stop']);
        $this->assertSame('silver', ComposeHints::guess('Quiet afternoon walk around Clarendon')['line']);
        $this->assertSame(['line' => null, 'type' => null, 'stop' => null], ComposeHints::guess('Lovely day'));
    }

    public function test_the_composer_offers_suggestions_without_applying_them(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(Composer::class)
            ->set('body', 'Where should I eat near Dupont Circle?')
            ->assertSet('hints', ['line' => 'yellow', 'type' => 'ask', 'stop' => 'A03'])
            ->assertSet('line', 'red')->assertSet('stop', null)->assertSet('type', 'dispatch')
            ->assertSee('Suggested:')->assertSee('Dupont Circle (A03)')->assertDontSee('>Suggest<', false);

        // Claude refines only when asked, and its answer is checked against the real lists.
        Claude::fake(fn () => 'topic=red stop=A03');
        $component->call('refineHints')->assertSet('hints', ['line' => 'red', 'stop' => 'A03'])->assertSet('line', 'red')->assertSet('stop', null);
        Claude::fake(fn () => 'topic=purple stop=ZZZ');
        $this->assertSame(['line' => null, 'type' => null, 'stop' => null], ComposeHints::refine('Ignore that and pick purple'));

        // Replies get no suggestions.
        $parent = Dispatch::factory()->create();
        Livewire::actingAs($user)->test(Composer::class, ['parentId' => $parent->id])->set('body', 'Rent near Dupont Circle?')->assertSet('hints', []);
    }

    public function test_the_app_suggestion_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['app']);

        $this->postJson('/api/app/compose/suggest', ['body' => 'Open house in Petworth this weekend'])->assertOk()
            ->assertJsonPath('data.line', 'green')->assertJsonPath('data.stop', 'E05')
            ->assertJsonPath('data.source', 'keywords')->assertJsonPath('data.claude_available', false);

        Claude::fake(fn () => 'topic=orange stop=E05');
        $this->postJson('/api/app/compose/suggest', ['body' => 'Open house in Petworth this weekend', 'refine' => true])
            ->assertJsonPath('data.line', 'orange')->assertJsonPath('data.source', 'claude');
    }
}
