<?php

namespace Tests\Feature;

use App\Livewire\Composer;
use App\Livewire\Pages\PlaceShow;
use App\Models\Dispatch;
use App\Models\Place;
use App\Models\Sponsorship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PlacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_been_here_posts_need_a_place_and_show_on_its_page(): void
    {
        $place = Place::create(['name' => 'Sample Taqueria', 'neighborhood' => 'Columbia Heights', 'stop' => 'E04', 'list_name' => 'Cheap Eats', 'list_rank' => 3]);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Composer::class)
            ->set('type', 'been')->set('body', 'The al pastor is the move')->call('file')->assertHasErrors('placeId');

        Livewire::actingAs($user)->test(Composer::class)
            ->set('type', 'been')->set('placeQuery', 'taqu')->assertSee('Sample Taqueria')
            ->call('selectPlace', $place->id)->set('body', 'The al pastor is the move')->call('file')->assertHasNoErrors();

        $dispatch = Dispatch::first();
        $this->assertSame('E04', $dispatch->stop, 'Picks up the place’s station.');
        $this->get(route('places.show', $place))->assertOk()->assertSee('The al pastor is the move')->assertSee('No. 3 · Cheap Eats')->assertSee('neighbor has been here');
        $this->get(route('places'))->assertOk()->assertSee('Sample Taqueria');
    }

    public function test_only_approved_owners_can_post_as_the_business(): void
    {
        $place = Place::create(['name' => 'Sample Bakery']);
        $owner = User::factory()->create();

        Livewire::actingAs($owner)->test(PlaceShow::class, ['place' => $place])
            ->set('claiming', true)->set('claimRole', 'Owner')->set('claimEvidence', 'Call the shop at 202-555-0100 and ask for me')->call('claim');
        $this->assertDatabaseHas('place_claims', ['place_id' => $place->id, 'status' => 'pending']);

        Livewire::actingAs($owner)->test(Composer::class)
            ->call('selectPlace', $place->id)->set('asBusiness', true)->set('body', 'Fresh croissants at 7')->call('file')->assertHasErrors('placeId');

        $place->update(['owner_id' => $owner->id]);
        Livewire::actingAs($owner)->test(Composer::class)
            ->call('selectPlace', $place->id)->set('asBusiness', true)->set('body', 'Fresh croissants at 7')->call('file')->assertHasNoErrors();

        $this->get('/?tab=latest')->assertSee('OWNER · SAMPLE BAKERY');
    }

    public function test_sponsored_placements_are_labeled_and_counted(): void
    {
        $sponsorship = Sponsorship::create([
            'sponsor' => 'Sample Bank', 'stop' => 'E04', 'headline' => 'Free checking for Columbia Heights',
            'url' => 'https://example.com/bank', 'starts_on' => now()->subDay(), 'ends_on' => now()->addWeek(),
        ]);

        $this->get('/?stop=E04')->assertSee('Sponsored · Sample Bank')->assertSee('Free checking');
        $this->get('/?stop=A03')->assertDontSee('Sample Bank');
        $this->get(route('sponsorships.click', $sponsorship))->assertRedirect('https://example.com/bank');

        $this->assertSame(1, $sponsorship->fresh()->clicks);
        $this->assertGreaterThanOrEqual(1, $sponsorship->fresh()->impressions);
    }

    public function test_only_staff_can_label_sponsored_callouts(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Composer::class)
            ->set('type', 'dispatch')->set('sponsor', 'Sample Bank')->set('body', 'Hello there')->call('file')->assertHasErrors('sponsor');

        Livewire::actingAs(User::factory()->create(['is_staff' => true]))->test(Composer::class)
            ->set('type', 'callout')->set('sponsor', 'Sample Bank')->set('body', 'Best first apartment neighborhood?')->call('file')->assertHasNoErrors();

        $this->get('/?tab=latest')->assertSee('Sponsored by Sample Bank');
    }
}
