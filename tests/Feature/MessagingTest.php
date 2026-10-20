<?php

namespace Tests\Feature;

use App\Livewire\Pages\ConversationView;
use App\Livewire\Pages\Inbox;
use App\Livewire\Pages\Moderation;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReport;
use App\Models\Penalty;
use App\Models\User;
use App\Support\Messaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_you_can_message_people_who_follow_you(): void
    {
        [$me, $friend, $stranger] = User::factory()->count(3)->create();
        $friend->following()->attach($me);

        Livewire::actingAs($me)->test(Inbox::class)->set('to', $friend->username)->call('start')->assertRedirect();
        Livewire::actingAs($me)->test(Inbox::class)->set('to', $stranger->username)->call('start')->assertHasErrors('to');

        $conversation = Conversation::firstOrFail();
        Livewire::actingAs($me)->test(ConversationView::class, ['conversation' => $conversation])
            ->set('body', 'Want to get pupusas Saturday?')->call('send');

        $this->assertSame('Want to get pupusas Saturday?', Message::first()->body);
        $this->assertNotSame('Want to get pupusas Saturday?', DB::table('messages')->value('body'), 'Bodies are encrypted at rest.');
        $this->assertSame(1, Messaging::unreadCount($friend));
    }

    public function test_message_requests_wait_for_acceptance_and_allow_one_message(): void
    {
        [$me, $them] = User::factory()->count(2)->create();
        $them->forceFill(['message_requests' => true])->save();

        $conversation = Messaging::start($me, [$them]);
        Messaging::send($me, $conversation->fresh('participants'), 'Hi, I saw your post about the flea market.');

        $this->expectException(ValidationException::class);
        try {
            Messaging::send($me, $conversation->fresh('participants'), 'Hello?');
        } finally {
            Livewire::actingAs($them)->test(Inbox::class)->set('box', 'requests')->assertSee($me->name);
            Livewire::actingAs($them)->test(ConversationView::class, ['conversation' => $conversation])->call('accept');
            Messaging::send($me, $conversation->fresh('participants'), 'Thanks for accepting!');
            $this->assertSame(2, $conversation->messages()->count());
        }
    }

    public function test_blocks_and_timeouts_stop_messages(): void
    {
        [$me, $friend] = User::factory()->count(2)->create();
        $friend->following()->attach($me);
        $conversation = Messaging::start($me, [$friend]);

        $friend->silenced()->attach($me->id, ['type' => 'block']);
        $this->assertNotSame('direct', Messaging::access($me, $friend));
        Livewire::actingAs($me)->test(ConversationView::class, ['conversation' => $conversation])
            ->set('body', 'hello')->call('send')->assertHasErrors('body');

        $friend->silenced()->detach($me->id);
        Penalty::create(['user_id' => $me->id, 'subject_hash' => $me->subjectHash(), 'type' => 'timeout', 'reason' => 'Spam', 'ends_at' => now()->addDay()]);
        Livewire::actingAs($me)->test(ConversationView::class, ['conversation' => $conversation])
            ->set('body', 'hello')->call('send')->assertHasErrors('body');
    }

    public function test_groups_reactions_receipts_and_photos(): void
    {
        Storage::fake('local');
        [$admin, $a, $b, $outsider] = User::factory()->count(4)->create();
        $a->following()->attach($admin);
        $b->following()->attach($admin);

        $group = Messaging::start($admin, [$a, $b], 'Brunch crew');
        $this->assertTrue($group->is_group);
        $this->assertTrue($group->fresh('participants')->isAdmin($admin));

        Livewire::actingAs($admin)->test(ConversationView::class, ['conversation' => $group])
            ->set('addUsername', $outsider->username)->call('addMember')->assertHasErrors('to');

        $view = Livewire::actingAs($admin)->test(ConversationView::class, ['conversation' => $group])
            ->set('photos', [UploadedFile::fake()->image('menu.jpg')])->call('send');
        $image = Message::first()->images->first();
        $this->actingAs($a)->get($image->url())->assertOk();
        $this->actingAs($outsider)->get($image->url())->assertNotFound();

        $message = Message::first();
        Livewire::actingAs($a)->test(ConversationView::class, ['conversation' => $group])
            ->call('react', $message->id, '❤️')->call('react', $message->id, '😂');
        $this->assertSame(2, $message->reactions()->count());
        Livewire::actingAs($a)->test(ConversationView::class, ['conversation' => $group])->call('react', $message->id, '❤️');
        $this->assertSame(1, $message->reactions()->count());

        Livewire::actingAs($a)->test(ConversationView::class, ['conversation' => $group])->call('leave');
        $this->assertFalse($group->participants()->whereKey($a->id)->exists());
    }

    public function test_reported_messages_reach_moderators_with_context(): void
    {
        [$me, $friend] = User::factory()->count(2)->create();
        $friend->following()->attach($me);
        $conversation = Messaging::start($me, [$friend]);
        Messaging::send($me, $conversation->fresh('participants'), 'You people should leave the neighborhood');
        $message = Message::first();

        Livewire::actingAs($friend)->test(ConversationView::class, ['conversation' => $conversation])
            ->set('reportingMessage', $message->id)->set('reportReason', 'hate')->call('reportMessage');

        $staff = User::factory()->create(['is_staff' => true]);
        Livewire::actingAs($staff)->test(Moderation::class)
            ->set('view', 'messages')->assertSee('You people should leave the neighborhood')
            ->call('resolveMessageReport', MessageReport::first()->id, 24);

        $this->assertNotNull($me->activeTimeout());
    }
}
