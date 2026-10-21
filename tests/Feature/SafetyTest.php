<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureNotBanned;
use App\Livewire\Composer;
use App\Livewire\Pages\AppealCreate;
use App\Livewire\Pages\Feed;
use App\Livewire\Pages\Moderation;
use App\Livewire\Settings\Safety;
use App\Models\Appeal;
use App\Models\Dispatch;
use App\Models\Penalty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\TestCase;

class SafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function staff(): User
    {
        return User::factory()->create(['is_staff' => true]);
    }

    public function test_safety_posts_reject_race_only_descriptions(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(Composer::class)
            ->set('type', 'safety')
            ->set('body', 'Car window smashed overnight')
            ->set('stop', 'E04')
            ->set('safetyWhen', 'Last night')
            ->set('safetyPerson', 'black male')
            ->call('file')
            ->assertHasErrors('safetyPerson');
        $this->assertDatabaseCount('dispatches', 0);

        $component->set('safetyPerson', 'Tall, gray hoodie, riding a red bike toward 14th St')->call('file')->assertHasNoErrors();
        $this->assertSame('Last night', Dispatch::first()->safety['when']);
    }

    public function test_removal_tells_the_author_why_and_applies_a_timeout(): void
    {
        $staff = $this->staff();
        $dispatch = Dispatch::factory()->create();

        Livewire::actingAs($staff)->test(Moderation::class)
            ->call('startAction', $dispatch->id)
            ->set('reason', 'Threats or harassment')
            ->set('penalty', '24')
            ->call('remove');

        $author = $dispatch->user;
        $this->assertSame('removed', $dispatch->fresh()->status);
        $this->assertStringContainsString('Threats or harassment', $author->notifications()->latest()->first()->data['message'] ?? $author->notifications()->first()->data['message']);
        $this->assertNotNull($author->activeTimeout());
        $this->assertStringContainsString('timeout', $author->postingBlock());
    }

    public function test_bans_survive_deleting_and_recreating_the_account(): void
    {
        $user = User::factory()->create(['email' => 'troll@example.com']);
        Penalty::create(['user_id' => $user->id, 'subject_hash' => $user->subjectHash(), 'type' => 'ban', 'reason' => 'Slurs']);

        $this->actingAs($user)->get('/')->assertRedirect(route('banned'));
        $this->actingAs($user)->get(route('banned'))->assertOk()->assertSee('Slurs');
        $this->actingAs($user)->get(route('appeals.create', ['penalty' => $user->activeBan()->id]))->assertOk();
        $this->assertSame('passed', (new EnsureNotBanned)->handle(
            tap(Request::create('/livewire/update', 'POST'), fn ($r) => $r->setUserResolver(fn () => $user)->setRouteResolver(fn () => app('router')->getRoutes()->getByName('default-livewire.update'))),
            fn () => response('passed'),
        )->getContent(), 'Banned users can still submit the appeal form through Livewire.');

        $user->delete();
        $again = User::factory()->create(['email' => 'TROLL@example.com']);

        $this->assertNotNull($again->activeBan());
    }

    public function test_appeals_go_to_a_different_moderator(): void
    {
        [$first, $second] = [$this->staff(), $this->staff()];
        $dispatch = Dispatch::factory()->create();

        Livewire::actingAs($first)->test(Moderation::class)
            ->call('startAction', $dispatch->id)->call('remove');

        Livewire::actingAs($dispatch->user)->test(AppealCreate::class, ['dispatch' => $dispatch->id])
            ->set('body', 'I was describing a restaurant, not a person.')
            ->call('submit');
        $appeal = Appeal::firstOrFail();

        Livewire::actingAs($first)->test(Moderation::class)->set('view', 'appeals')->assertDontSee('I was describing a restaurant');
        Livewire::actingAs($first)->test(Moderation::class)->call('decide', $appeal->id, true)->assertForbidden();

        Livewire::actingAs($second)->test(Moderation::class)->call('decide', $appeal->id, true);
        $this->assertSame('approved', $dispatch->fresh()->status);

        Livewire::actingAs($dispatch->user)->test(AppealCreate::class, ['dispatch' => $dispatch->id])->assertNotFound();
    }

    public function test_reply_limits_and_hidden_replies(): void
    {
        $author = User::factory()->create(['replies_from' => 'following']);
        $post = Dispatch::factory()->for($author)->create();
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)->test(Composer::class, ['parentId' => $post->id])
            ->set('body', 'Hello from a stranger')->call('file')->assertHasErrors('body');

        $author->following()->attach($stranger);
        Livewire::actingAs($stranger)->test(Composer::class, ['parentId' => $post->id])
            ->set('body', 'Hello from a friend')->call('file')->assertHasNoErrors();

        $reply = $post->replies()->first();
        Livewire::actingAs($author)->test(Feed::class)->call('toggleHideReply', $reply->id);
        $this->assertTrue($reply->fresh()->hidden_by_author);
        $this->get(route('dispatches.show', $post))->assertSee('A reply hidden by the author');
    }

    public function test_pile_ons_pause_replies_and_flag_the_thread(): void
    {
        config(['stoop.pile_on_threshold' => 3]);
        $post = Dispatch::factory()->create();

        foreach (User::factory()->count(3)->create() as $user) {
            Livewire::actingAs($user)->test(Composer::class, ['parentId' => $post->id])->set('body', 'You are wrong about this')->call('file');
        }

        $post->refresh();
        $this->assertNotNull($post->flagged_at);
        $this->assertTrue($post->replies_locked_until->isFuture());

        Livewire::actingAs(User::factory()->create())->test(Composer::class, ['parentId' => $post->id])
            ->set('body', 'One more')->call('file')->assertHasErrors('body');
        Livewire::actingAs($this->staff())->test(Moderation::class)->assertSee('pile-on');
    }

    public function test_safety_settings_undo_mutes_and_set_reply_limits(): void
    {
        $user = User::factory()->create();
        $muted = User::factory()->create();
        $user->silenced()->attach($muted->id, ['type' => 'mute']);

        Livewire::actingAs($user)->test(Safety::class)
            ->assertSee($muted->username)
            ->call('undo', $muted->id, 'mute')
            ->set('repliesFrom', 'following');

        $this->assertSame([], $user->hiddenUserIds());
        $this->assertSame('following', $user->fresh()->replies_from);
    }

    public function test_unverified_accounts_cannot_post_and_the_transparency_page_is_public(): void
    {
        Livewire::actingAs(User::factory()->unverified()->create())->test(Composer::class)
            ->set('body', 'Hello there neighbors')->call('file')->assertHasErrors('body');

        $this->get(route('transparency'))->assertOk()->assertSee('Dispatches filed');
    }
}
