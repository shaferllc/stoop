<?php

namespace Tests\Feature;

use App\Livewire\Composer;
use App\Livewire\Pages\Feed;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\Achievements;
use App\Support\Bots;
use App\Support\Mentions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LocalTouchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_terms_get_a_definition_once_and_never_inside_links(): void
    {
        $html = (string) Mentions::render('Grabbed a half-smoke, then another half-smoke. See https://example.com/half-smoke <b>');

        $this->assertSame(1, substr_count($html, '<abbr'));
        $this->assertStringContainsString('title="DC’s signature sausage', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringContainsString('href="https://example.com/half-smoke"', $html);
    }

    public function test_mumbo_sauce_is_a_reaction_anyone_can_give(): void
    {
        $post = Dispatch::factory()->create();
        $fans = User::factory()->count(10)->create();

        foreach ($fans as $fan) {
            Livewire::actingAs($fan)->test(Feed::class)->call('toggleMark', $post->id, 'mumbo');
        }

        $this->assertArrayHasKey('sauced', Achievements::unlocked($post->user));
        Livewire::actingAs($fans[0])->test(Feed::class, ['tab' => 'latest'])->assertSee('Mumbo sauce');
    }

    public function test_escalator_posts_earn_stand_right_with_confetti(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Composer::class)
            ->set('body', 'Wheaton escalator is out again, take the elevator')->call('file')
            ->assertDispatched('confetti');
    }

    public function test_go_go_friday_posts_once(): void
    {
        $this->artisan('stoop:go-go-friday')->assertSuccessful();
        $this->artisan('stoop:go-go-friday')->assertSuccessful();

        $this->assertSame(1, Bots::account('dc')->dispatches()->count());
    }
}
