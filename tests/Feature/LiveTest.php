<?php

namespace Tests\Feature;

use App\Events\DispatchPublished;
use App\Events\MessageSent;
use App\Events\UserNotified;
use App\Livewire\Composer;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\Messaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class LiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_posts_messages_and_notifications_broadcast(): void
    {
        Event::fake([DispatchPublished::class, MessageSent::class, UserNotified::class]);
        [$me, $friend] = User::factory()->count(2)->create();
        $friend->following()->attach($me);

        Livewire::actingAs($me)->test(Composer::class)->set('body', 'Hello @'.$friend->username)->call('file');
        Messaging::send($me, Messaging::start($me, [$friend])->fresh('participants'), 'Hi there');

        Event::assertDispatched(DispatchPublished::class);
        Event::assertDispatched(MessageSent::class);
        Event::assertDispatched(UserNotified::class, fn ($e) => $e->userId === $friend->id && $e->kind === 'mention');
        Event::assertDispatched(UserNotified::class, fn ($e) => $e->userId === $friend->id && $e->kind === 'message');
    }

    public function test_a_broken_broadcaster_never_breaks_posting(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.options.host' => '127.0.0.1', 'broadcasting.connections.reverb.options.port' => 1]);

        Livewire::actingAs(User::factory()->create())->test(Composer::class)->set('body', 'Still posts')->call('file')->assertHasNoErrors();

        $this->assertSame(1, Dispatch::count());
    }

    public function test_devices_can_sign_up_for_push(): void
    {
        $user = User::factory()->create();
        $payload = ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'key', 'auth' => 'secret']];

        $this->actingAs($user)->postJson(route('push.subscribe'), $payload)->assertNoContent();
        $this->actingAs($user)->postJson(route('push.subscribe'), $payload)->assertNoContent();
        $this->assertSame(1, DB::table('push_subscriptions')->count());

        $this->actingAs($user)->deleteJson(route('push.unsubscribe'), ['endpoint' => 'https://push.example/abc'])->assertNoContent();
        $this->assertSame(0, DB::table('push_subscriptions')->count());

        $this->actingAs($user)->postJson(route('push.subscribe'), ['endpoint' => 'http://insecure.example'])->assertUnprocessable();
    }

    public function test_phones_get_a_tab_bar_and_composer_sheet(): void
    {
        $this->actingAs(User::factory()->create())->get('/')
            ->assertSee('aria-label="File a dispatch"', false)
            ->assertSee('open-composer', false);
    }
}
