<?php

namespace Tests\Feature;

use App\Bots\Bot;
use App\Jobs\RunBots;
use App\Models\Dispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotFrameworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bot_class_answers_when_mentioned_once(): void
    {
        $bot = new class extends Bot
        {
            public function username(): string
            {
                return 'echo';
            }

            public function name(): string
            {
                return 'Echo';
            }

            public function description(): string
            {
                return 'Says hi back.';
            }

            public function reply(Dispatch $dispatch): ?string
            {
                return 'Hi from Echo.';
            }
        };
        $this->assertSame([], array_filter(Bot::all(), fn ($b) => $b->username() === 'echo'));

        $dispatch = Dispatch::factory()->create(['body' => 'Hey @echo what is up', 'status' => 'approved']);
        $this->assertTrue($bot->wantsToReply($dispatch));
        $post = $bot->post(['parentId' => $dispatch->id, 'body' => $bot->reply($dispatch)]);

        $this->assertTrue($post->user->is_bot);
        $this->assertSame('echo', $post->user->username);
        $this->assertSame('Hi from Echo.', $post->body);
        (new RunBots($dispatch))->handle();
        $this->assertSame(1, $dispatch->replies()->count());
    }
}
