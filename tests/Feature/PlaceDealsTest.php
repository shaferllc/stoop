<?php

namespace Tests\Feature;

use App\Livewire\Admin\ReadersPicks;
use App\Livewire\Pages\Feed;
use App\Livewire\Pages\PlaceShow;
use App\Livewire\PlaceDealPanel;
use App\Models\Dispatch;
use App\Models\Place;
use App\Models\User;
use App\Support\ReadersPicks as Picks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class PlaceDealsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function test_owners_post_one_deal_at_a_time_shown_on_the_place_and_its_stop(): void
    {
        [$owner, $stranger] = User::factory()->count(2)->create();
        $place = Place::create(['name' => "Ben's Chili Bowl", 'stop' => 'E03', 'owner_id' => $owner->id]);
        $ends = now('America/New_York')->addDays(3)->format('Y-m-d\TH:i');

        Livewire::actingAs($stranger)->test(PlaceDealPanel::class, ['placeId' => $place->id])->call('post')->assertForbidden();

        $panel = Livewire::actingAs($owner)->test(PlaceDealPanel::class, ['placeId' => $place->id])
            ->set('deal.title', 'Half-smokes $5 before 6')->set('deal.ends_at', $ends)->call('post')->assertHasNoErrors();
        $panel->set('posting', true)->set('deal.title', 'Second deal')->set('deal.ends_at', $ends)->call('post')->assertHasErrors('deal.title');
        $this->assertSame(1, DB::table('place_deals')->count());

        Livewire::test(PlaceShow::class, ['place' => $place])->assertSee('Deal from the owner')->assertSee('Half-smokes $5 before 6');
        Livewire::test(Feed::class, ['stop' => 'E03'])->assertSee('Deal · Ben')->assertSee('Half-smokes $5 before 6');
        Livewire::test(Feed::class, ['stop' => 'A01'])->assertDontSee('Half-smokes $5 before 6');

        $panel->call('end', DB::table('place_deals')->value('id'));
        Livewire::test(Feed::class, ['stop' => 'E03'])->assertDontSee('Half-smokes $5 before 6');
    }

    public function test_deals_reject_bad_dates_and_swearing_and_work_in_the_app(): void
    {
        $owner = User::factory()->create();
        $place = Place::create(['name' => 'Songbyrd', 'stop' => 'E04', 'owner_id' => $owner->id]);

        Sanctum::actingAs($owner, ['app']);
        $this->postJson("/api/app/places/{$place->id}/deals", ['title' => 'Free show', 'ends_at' => now()->subDay()->toDateTimeString()])->assertStatus(422);
        $this->postJson("/api/app/places/{$place->id}/deals", ['title' => 'Free damn show', 'ends_at' => now()->addDay()->toDateTimeString()])->assertStatus(422);
        $id = $this->postJson("/api/app/places/{$place->id}/deals", ['title' => 'Free show Friday', 'ends_at' => now()->addDays(2)->toDateTimeString()])->assertCreated()->json('data.id');
        $this->getJson('/api/app/deals?stop=E04')->assertOk()->assertJsonPath('data.0.title', 'Free show Friday');

        Sanctum::actingAs(User::factory()->create(), ['app']);
        $this->postJson("/api/app/deals/$id/end")->assertForbidden();
        $this->postJson("/api/app/places/{$place->id}/deals", ['title' => 'Mine now', 'ends_at' => now()->addDay()->toDateTimeString()])->assertForbidden();
    }

    public function test_readers_picks_report_and_csv_for_staff(): void
    {
        $staff = User::factory()->create(['is_staff' => true]);
        $diner = User::factory()->create(['username' => 'diner']);
        $place = Place::create(['name' => 'Rose’s Luxury', 'list_name' => '100 Best Restaurants', 'list_rank' => 1]);
        Place::create(['name' => 'Quiet Spot', 'list_name' => '100 Best Restaurants', 'list_rank' => 2]);
        $post = Dispatch::factory()->for($diner)->create(['type' => 'been', 'place_id' => $place->id, 'body' => '=HYPERLINK("x") loved it, amazing pasta, a bit slow']);
        DB::table('marks')->insert(['user_id' => $diner->id, 'dispatch_id' => $post->id, 'kind' => 'been', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(0.33, round(Picks::sentiment('loved it, amazing pasta, a bit slow'), 2));
        $row = Picks::forList('100 Best Restaurants')->first();
        $this->assertSame([1, 1], [$row['checkins'], $row['posts']]);

        Livewire::actingAs(User::factory()->create())->test(ReadersPicks::class)->assertForbidden();
        Livewire::actingAs($staff)->test(ReadersPicks::class)->assertSee('Rose’s Luxury')->assertSee('0.33')->assertSee('@diner');

        $this->actingAs(User::factory()->create())->get(route('admin.readers-picks.csv', ['list' => '100 Best Restaurants']))->assertForbidden();
        $csv = $this->actingAs($staff)->get(route('admin.readers-picks.csv', ['list' => '100 Best Restaurants']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Rose’s Luxury', $csv);
        $this->assertStringContainsString("'@diner: =HYPERLINK", $csv);
        $this->assertStringNotContainsString($diner->email, $csv);
    }
}
