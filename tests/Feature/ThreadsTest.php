<?php

namespace Tests\Feature;

use App\Actions\FileDispatch;
use App\Jobs\RunBots;
use App\Livewire\Composer;
use App\Models\Dispatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ThreadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_thread_page_shows_replies_to_replies(): void
    {
        config(['services.openai.key' => null, 'services.anthropic.key' => null]);
        [$a, $b, $c] = User::factory()->count(3)->create();
        $root = Dispatch::factory()->for($a)->create(['body' => 'Best half-smoke in Shaw?']);
        $first = FileDispatch::handle($b, ['parentId' => $root->id, 'body' => 'Ben’s, obviously.'], confirmed: true);
        $second = FileDispatch::handle($c, ['parentId' => $first->id, 'body' => 'Tourist answer, try the cart on 7th.'], confirmed: true);
        FileDispatch::handle($a, ['parentId' => $second->id, 'body' => 'Which cart exactly?'], confirmed: true);

        $this->actingAs($a)->get(route('dispatches.show', $root))
            ->assertOk()
            ->assertSeeInOrder(['Ben’s, obviously.', 'Tourist answer, try the cart on 7th.', 'Which cart exactly?'])
            ->assertSee('Hide 2 replies');
    }

    public function test_replying_to_a_reply_from_the_thread_page(): void
    {
        config(['services.openai.key' => null, 'services.anthropic.key' => null]);
        [$a, $b] = User::factory()->count(2)->create();
        $root = Dispatch::factory()->for($a)->create();
        $reply = FileDispatch::handle($b, ['parentId' => $root->id, 'body' => 'First reply here.'], confirmed: true);

        Livewire::actingAs($a)->test(Composer::class, ['parentId' => $root->id])
            ->dispatch('reply-to', id: $reply->id)
            ->assertSee('Replying to')
            ->set('body', 'Answering the reply directly.')
            ->call('file')
            ->assertSet('parentId', $root->id);

        $this->assertSame($reply->id, Dispatch::where('body', 'Answering the reply directly.')->value('parent_id'));
    }

    public function test_feed_cards_can_peek_at_the_latest_replies(): void
    {
        config(['services.openai.key' => null, 'services.anthropic.key' => null]);
        [$a, $b] = User::factory()->count(2)->create();
        $root = Dispatch::factory()->for($a)->create();
        FileDispatch::handle($b, ['parentId' => $root->id, 'body' => 'A <b>peek</b> reply'], confirmed: true);

        $this->get(route('dispatches.replies-preview', $root))->assertOk()->assertSee('A &lt;b&gt;peek&lt;/b&gt; reply', false);
        $root->forceFill(['status' => 'removed'])->save();
        $this->get(route('dispatches.replies-preview', $root))->assertNotFound();
    }

    public function test_a_mentioned_bot_always_says_how_to_use_it(): void
    {
        config(['services.openai.key' => null, 'services.anthropic.key' => null]);
        $post = Dispatch::factory()->create(['body' => '@recap what do you do?']);

        (new RunBots($post))->handle();

        $this->assertStringContainsString('a Stoop bot', $post->replies()->first()->body);
    }

    public function test_typing_at_lists_the_bots_after_people(): void
    {
        $items = Livewire::actingAs(User::factory()->create())->test(Composer::class)->instance()->mentionSuggestions('');

        $this->assertContains('metro', array_column(array_filter($items, fn ($i) => $i['bot'] ?? false), 'username'));
        $this->assertContains('weather', array_column($items, 'username'));
    }
}
