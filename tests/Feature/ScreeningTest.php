<?php

namespace Tests\Feature;

use App\Livewire\Composer;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\ModerationModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ScreeningTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ModerationModel::fake(null);
        parent::tearDown();
    }

    public function test_the_model_publishes_or_holds_new_dispatches(): void
    {
        ModerationModel::fake(fn ($text) => str_contains($text, 'go back') ? 'hold' : 'allow');
        $user = User::factory()->create();
        $maya = User::factory()->create(['username' => 'maya']);

        Livewire::actingAs($user)->test(Composer::class)->set('body', 'Pupusas with @maya tonight')->call('file');
        Livewire::actingAs($user)->test(Composer::class)->set('body', 'They should all go back where they came from')->call('file');

        $this->assertSame('approved', Dispatch::firstWhere('body', 'like', 'Pupusas%')->status);
        $this->assertSame('held', Dispatch::firstWhere('body', 'like', 'They should%')->status);
        $this->assertSame(1, $maya->notifications()->count(), 'Mentions notify only once the post is approved.');
    }

    public function test_a_failed_check_never_approves(): void
    {
        ModerationModel::fake(fn () => throw new \RuntimeException('API down'));

        Livewire::actingAs(User::factory()->create())->test(Composer::class)
            ->set('body', 'Hello neighbors')->call('file')->assertHasNoErrors();

        $this->assertSame('pending', Dispatch::first()->status);
        $this->actingAs(User::factory()->create())->get('/?tab=latest')->assertDontSee('Hello neighbors');
    }

    public function test_staff_skip_the_model_check(): void
    {
        ModerationModel::fake(fn () => 'hold');

        Livewire::actingAs(User::factory()->create(['is_staff' => true]))->test(Composer::class)->set('body', 'Staff note')->call('file');

        $this->assertSame('approved', Dispatch::first()->status);
    }

    public function test_turnstile_is_required_when_configured(): void
    {
        config(['services.turnstile.secret' => 'secret', 'services.turnstile.site_key' => 'site']);
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);
        $form = ['name' => 'Bot', 'username' => 'botty', 'email' => 'bot@example.com', 'password' => 'password-123-Long!', 'password_confirmation' => 'password-123-Long!'];

        $this->post(route('register.store'), $form)->assertSessionHasErrors('cf-turnstile-response');
        $this->post(route('register.store'), $form + ['cf-turnstile-response' => 'token'])->assertSessionHasErrors('cf-turnstile-response');

        $this->post(route('register.store'), $form + ['cf-turnstile-response' => 'token'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get(route('register'))->assertSee('cf-turnstile', false);
    }

    public function test_new_accounts_have_a_daily_post_cap(): void
    {
        config(['stoop.new_account_daily_posts' => 2]);
        $user = User::factory()->create();
        Dispatch::factory()->count(2)->for($user)->create();

        Livewire::actingAs($user)->test(Composer::class)->set('body', 'Third post today')->call('file')->assertHasErrors('body');

        $user->forceFill(['created_at' => now()->subMonth()])->save();
        Livewire::actingAs($user)->test(Composer::class)->set('body', 'Third post today')->call('file')->assertHasNoErrors();
    }

    public function test_privacy_and_terms_pages(): void
    {
        $this->get(route('privacy'))->assertOk()->assertSee('Download your data');
        $this->get(route('terms'))->assertOk()->assertSee('appeal each decision once');
    }
}
