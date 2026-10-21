<?php

namespace Tests\Feature;

use App\Livewire\Composer;
use App\Mail\WeeklyDigest;
use App\Models\Dispatch;
use App\Models\LinkPreview;
use App\Models\User;
use App\Support\Magazine;
use App\Support\Newsletter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class MagazineTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_articles_post_once_on_the_right_line(): void
    {
        config(['stoop.magazine_feed' => 'https://magazine.example/feed']);
        Http::fake([
            'magazine.example/feed' => Http::response('<?xml version="1.0"?><rss><channel>
                <item><title>The 25 Best Cheap Eats</title><link>https://magazine.example/cheap-eats</link><guid>a1</guid><category>Food</category></item>
                <item><title>Homes for sale in Petworth</title><link>https://magazine.example/petworth</link><guid>a2</guid><category>Real Estate</category></item>
                <item><title>A doctor’s approach to recovery</title><link>https://magazine.example/paid</link><guid>a3</guid><category>Sponsored</category></item>
            </channel></rss>'),
            '*' => Http::response('', 404),
        ]);

        $this->assertSame(2, Magazine::import());
        $this->assertSame(0, Magazine::import(), 'Nothing posts twice.');
        $this->assertNull(Dispatch::where('body', 'like', '%doctor%')->first(), 'Sponsored articles are never posted.');

        $food = Dispatch::where('body', 'like', '%Cheap Eats%')->first();
        $this->assertSame('red', $food->line);
        $this->assertSame('green', Dispatch::where('body', 'like', '%Petworth%')->value('line'));
        $this->assertSame('blue', Magazine::lineFor('I Scored a Buzzer-Beater as a Washington Wizard'));
        $this->assertSame('red', Magazine::lineFor('Where to eat in Shaw'));
        $this->assertSame('magazine', $food->user->username);
    }

    public function test_the_subscription_webhook_needs_a_valid_signature(): void
    {
        config(['services.subscriptions.secret' => 'shh']);
        $user = User::factory()->create(['email' => 'reader@example.com']);
        $body = json_encode(['email' => 'READER@example.com', 'subscribed_until' => now()->addYear()->toDateString()]);

        $this->call('POST', '/api/subscriptions', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_STOOP_SIGNATURE' => 'sha256=wrong'], $body)->assertUnauthorized();
        $this->call('POST', '/api/subscriptions', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_STOOP_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'shh')], $body)->assertOk();

        $this->assertTrue($user->fresh()->subscribed_until->isFuture());

        // Someone the magazine already knows about is a subscriber the moment they sign up.
        $later = json_encode(['email' => 'new@example.com', 'subscribed_until' => now()->addYear()->toDateString()]);
        $this->call('POST', '/api/subscriptions', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_STOOP_SIGNATURE' => 'sha256='.hash_hmac('sha256', $later, 'shh')], $later)->assertOk();
        $this->post(route('register.store'), ['name' => 'New', 'username' => 'newreader', 'email' => 'new@example.com', 'password' => 'password-123-Long!', 'password_confirmation' => 'password-123-Long!']);
        $this->assertTrue(User::firstWhere('username', 'newreader')->subscribed_until->isFuture());
    }

    public function test_article_embed_and_join_link(): void
    {
        $preview = LinkPreview::create(['url_hash' => hash('sha256', 'https://magazine.example/pizza'), 'url' => 'https://magazine.example/pizza', 'title' => 'Pizza']);
        $post = Dispatch::factory()->create(['link_preview_id' => $preview->id, 'body' => 'This list is wrong about pizza']);
        Dispatch::factory()->create(['parent_id' => $post->id, 'body' => 'No it isn’t']);

        $this->getJson('/api/discussions?url='.urlencode('https://magazine.example/pizza'))
            ->assertOk()->assertJsonPath('count', 2)->assertJsonCount(2, 'latest');
        $this->get(route('embed'))->assertOk()->assertSee('data-stoop-discussion', false);

        $this->get('/?share='.urlencode('https://magazine.example/pizza'));
        Livewire::withQueryParams(['share' => 'https://magazine.example/pizza'])->actingAs(User::factory()->create())
            ->test(Composer::class)->assertSet('body', ' https://magazine.example/pizza');
    }

    public function test_weekly_newsletter_goes_to_people_who_opted_in_and_can_be_unsubscribed(): void
    {
        Mail::fake();
        $reader = User::factory()->create(['newsletter' => true]);
        User::factory()->create(['newsletter' => false]);
        Dispatch::factory()->create(['body' => 'Best dispatch of the week']);

        $this->artisan('stoop:send-newsletter')->assertSuccessful();

        Mail::assertQueued(WeeklyDigest::class, 1);
        Mail::assertQueued(WeeklyDigest::class, fn ($mail) => $mail->hasTo($reader->email));
        $this->assertStringContainsString('Best dispatch of the week', (new WeeklyDigest(Newsletter::digest(), $reader))->render());

        $link = URL::signedRoute('newsletter.unsubscribe', ['user' => $reader->id]);
        $this->get($link)->assertOk()->assertSee('Stop the weekly email?');
        $this->assertTrue($reader->fresh()->newsletter, 'Opening the link alone doesn’t unsubscribe.');
        $this->post($link)->assertOk();
        $this->assertFalse($reader->fresh()->newsletter);
        $this->get(route('newsletter.unsubscribe', ['user' => $reader->id]))->assertForbidden();
    }
}
