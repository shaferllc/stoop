<?php

namespace Tests\Feature;

use App\Livewire\Composer;
use App\Livewire\HappeningHost;
use App\Livewire\Pages\HappeningsCalendar;
use App\Livewire\Pages\ShowDispatch;
use App\Models\Dispatch;
use App\Models\Place;
use App\Models\User;
use App\Support\Happenings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class HappeningsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['stoop.model_moderation' => false, 'services.openai.key' => null]);
    }

    /**
     * A happening posted through the composer with a start time (DC time).
     */
    protected function happening(User $host, string $startsAt, array $extra = []): Dispatch
    {
        Livewire::actingAs($host)->test(Composer::class)
            ->set('type', 'happening')->set('body', $extra['body'] ?? 'Go-go on the Mall, bring a blanket')
            ->set('line', $extra['line'] ?? 'orange')->set('stop', $extra['stop'] ?? 'D02')
            ->set('happeningStarts', $startsAt)->call('file')->assertHasNoErrors();

        return Dispatch::latest('id')->first();
    }

    public function test_posting_with_a_start_time_puts_it_on_the_calendar_in_dc_time(): void
    {
        $this->travelTo(now()->setTimezone('America/New_York')->setTime(9, 0)->utc());
        $host = User::factory()->create();
        $tomorrow = now('America/New_York')->addDay()->format('Y-m-d');
        $d = $this->happening($host, $tomorrow.'T20:00');

        $this->assertSame('20:00', $d->happening->starts_at->timezone('America/New_York')->format('H:i'));
        $this->assertNotEmpty($d->happening_at); // the free-text "when" is filled in
        $this->get(route('happenings'))->assertOk()->assertSee('Go-go on the Mall');
        $this->get(route('lost-found'))->assertOk();
        $this->actingAs($host)->get(route('bingo'))->assertOk()->assertSee('Metro Bingo');
        $this->actingAs($host)->get(route('passports'))->assertOk()->assertSee('Scavenger hunt stamps');
        Livewire::test(HappeningsCalendar::class)->assertSee('Tomorrow')->assertSee('Go-go on the Mall')->assertSee('8:00pm');
        Livewire::test(HappeningsCalendar::class, ['line' => 'red'])->assertDontSee('Go-go on the Mall');
        Livewire::test(HappeningsCalendar::class, ['stop' => 'D02'])->assertSee('Go-go on the Mall');
    }

    public function test_going_and_interested_are_one_rsvp_and_only_the_host_sees_names(): void
    {
        [$host, $guest, $other] = User::factory()->count(3)->create();
        $d = $this->happening($host, now('America/New_York')->addDays(2)->format('Y-m-d\TH:i'));

        Livewire::actingAs($guest)->test(ShowDispatch::class, ['dispatch' => $d->id])->call('toggleMark', $d->id, 'interested');
        $this->assertSame(['going' => 1, 'interested' => 1], Happenings::counts($d)); // the host is going
        Livewire::actingAs($guest)->test(ShowDispatch::class, ['dispatch' => $d->id])->call('toggleMark', $d->id, 'going');
        $this->assertSame(['going' => 2, 'interested' => 0], Happenings::counts($d));

        $rsvps = Happenings::rsvps($d);
        $this->assertSame([$guest->id], $rsvps['going']->pluck('id')->all()); // without the host

        Livewire::actingAs($host)->test(ShowDispatch::class, ['dispatch' => $d->id])->assertSee('For the host')->assertSee('@'.$guest->username);
        Livewire::actingAs($other)->test(ShowDispatch::class, ['dispatch' => $d->id])->assertDontSee('For the host');
        Livewire::actingAs($other)->test(HappeningHost::class, ['dispatchId' => $d->id])->assertForbidden();
    }

    public function test_ics_download_and_personal_feed(): void
    {
        [$host, $guest] = User::factory()->count(2)->create();
        $d = $this->happening($host, now('America/New_York')->addDays(3)->format('Y-m-d').'T19:30', ['body' => "Trivia night; bring friends, all welcome\nSecond line"]);

        $ics = $this->get(route('happenings.ics', $d))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=UTF-8')->getContent();
        $this->assertStringContainsString("BEGIN:VEVENT\r\n", $ics);
        $this->assertStringContainsString('SUMMARY:Trivia night\; bring friends\, all welcome', $ics);
        $this->assertStringContainsString('DTSTART:'.$d->happening->starts_at->utc()->format('Ymd\THis\Z'), $ics);
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }

        DB::table('marks')->insert(['user_id' => $guest->id, 'dispatch_id' => $d->id, 'kind' => 'interested', 'created_at' => now(), 'updated_at' => now()]);
        $token = Happenings::feedToken($guest);
        $this->get(route('calendar.feed', $token))->assertOk()->assertSee('Trivia night');
        $this->get('/calendar/'.str_repeat('a', 40).'.ics')->assertNotFound();

        Happenings::feedToken($guest, reset: true);
        $this->get(route('calendar.feed', $token))->assertNotFound();
    }

    public function test_tickets_are_for_owners_and_staff_and_clicks_are_counted(): void
    {
        [$host, $owner] = User::factory()->count(2)->create();
        Place::create(['name' => 'Songbyrd', 'owner_id' => $owner->id]);
        $d = $this->happening($host, now('America/New_York')->addDays(2)->format('Y-m-d\TH:i'));

        Livewire::actingAs($host)->test(HappeningHost::class, ['dispatchId' => $d->id])->assertDontSee('Ticket link');
        $this->expectsValidation(fn () => Happenings::saveDetails($host, $d, ['ticket_url' => 'https://tickets.example.com']));

        $show = $this->happening($owner, now('America/New_York')->addDays(4)->format('Y-m-d\TH:i'), ['body' => 'Album release show']);
        Livewire::actingAs($owner)->test(HappeningHost::class, ['dispatchId' => $show->id])
            ->set('form.price', '15')->set('form.ticket_url', 'http://insecure.example.com')->call('save')->assertHasErrors('ticket_url')
            ->set('form.ticket_url', 'https://tickets.example.com/show')->call('save')->assertHasNoErrors();

        $this->assertSame(1500, $show->happening->fresh()->price_cents);
        Livewire::test(ShowDispatch::class, ['dispatch' => $show->id])->assertSee('TICKETED · $15')->assertSee('Get tickets');

        $this->get(route('happenings.tickets', $show))->assertRedirect('https://tickets.example.com/show');
        $this->assertSame(1, $show->happening->fresh()->ticket_clicks);
        $this->get(route('happenings.tickets', $d))->assertNotFound();
    }

    public function test_only_staff_can_feature_a_happening(): void
    {
        $host = User::factory()->create();
        $staff = User::factory()->create(['is_staff' => true]);
        $d = $this->happening($host, now('America/New_York')->addDays(2)->format('Y-m-d\TH:i'));

        Livewire::actingAs($host)->test(HappeningHost::class, ['dispatchId' => $d->id])->call('toggleFeatured')->assertForbidden();
        Livewire::actingAs($staff)->test(HappeningHost::class, ['dispatchId' => $d->id])->call('toggleFeatured');

        $this->assertTrue($d->happening->fresh()->featured);
        $this->assertDatabaseHas('audit_logs', ['action' => 'happening.featured', 'actor_id' => $staff->id]);
    }

    public function test_app_api_lists_rsvps_and_updates_happenings(): void
    {
        [$host, $guest] = User::factory()->count(2)->create();
        $d = $this->happening($host, now('America/New_York')->addDays(1)->format('Y-m-d\TH:i'));

        Sanctum::actingAs($guest, ['app']);
        $this->postJson("/api/app/dispatches/{$d->id}/marks/interested")->assertOk()->assertJsonPath('on', true);
        $this->getJson('/api/app/happenings')->assertOk()->assertJsonPath('data.0.id', $d->id)->assertJsonPath('data.0.happening.interested', 1);
        $this->getJson("/api/app/happenings/{$d->id}/rsvps")->assertForbidden();
        $this->postJson("/api/app/happenings/{$d->id}", ['featured' => true])->assertForbidden();
        $this->assertStringContainsString('/calendar/', $this->postJson('/api/app/calendar-feed')->assertOk()->json('url'));

        Sanctum::actingAs($host, ['app']);
        $this->getJson("/api/app/happenings/{$d->id}/rsvps")->assertOk()->assertJsonPath('data.interested.0.username', $guest->username)
            ->assertJsonMissing(['email' => $guest->email]);
        $this->postJson("/api/app/happenings/{$d->id}", ['starts_at' => now('America/New_York')->addDays(5)->format('Y-m-d H:i')])->assertOk();
        $this->postJson('/api/app/dispatches', ['type' => 'happening', 'body' => 'Porch concert', 'line' => 'orange', 'happening_starts' => now()->addDays(2)->format('Y-m-d H:i')])
            ->assertCreated();
    }

    protected function expectsValidation(\Closure $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a validation error.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
