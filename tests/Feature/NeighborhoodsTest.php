<?php

namespace Tests\Feature;

use App\Livewire\StationLive;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\Achievements;
use App\Support\Neighborhoods;
use App\Support\Stations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class NeighborhoodsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_dc_station_has_a_ward_and_an_anc_with_a_meetings_page(): void
    {
        $dc = array_keys(array_filter(config('metro.stations'), fn ($s) => $s[1] === 'DC'));

        $this->assertEqualsCanonicalizing($dc, array_keys(config('neighborhoods.stations')));
        foreach ($dc as $code) {
            $this->assertContains(Neighborhoods::ward($code), range(1, 8));
            $this->assertStringStartsWith('https://oanc.dc.gov/taxonomy/term/', Neighborhoods::ancUrl(Neighborhoods::anc($code)));
            $this->assertSame(Neighborhoods::ward($code), Neighborhoods::wardOfAnc(Neighborhoods::anc($code)));
        }
        $this->assertNull(Neighborhoods::ward('K04'), 'Virginia stations have no ward.');
        $this->assertSame('https://oanc.dc.gov/taxonomy/term/19', Neighborhoods::ancUrl('1B'));
    }

    public function test_station_pages_show_the_ward_anc_and_nearby_311_requests(): void
    {
        Http::fake([
            'maps2.dcgis.dc.gov/*' => Http::response(['features' => [
                ['attributes' => ['SERVICECODEDESCRIPTION' => 'Pothole', 'STREETADDRESS' => '1400 IRVING STREET NW', 'ADDDATE' => now()->subDay()->getTimestampMs(), 'SERVICEORDERSTATUS' => 'Open']],
            ]]),
            '*' => Http::response([]),
        ]);

        Livewire::withoutLazyLoading()->test(StationLive::class, ['code' => 'E04'])
            ->assertSee('Ward 1 · ANC 1A')->assertSee('https://oanc.dc.gov/taxonomy/term/18')
            ->assertSee('Pothole')->assertSee('1400 Irving Street NW')->assertSee('Pepco outage map');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'geometry=-77'));
    }

    public function test_the_311_box_has_clear_empty_states(): void
    {
        Http::fake(); // An empty response, like DC GIS with nothing nearby.
        Livewire::withoutLazyLoading()->test(StationLive::class, ['code' => 'A03'])->assertSee('No 311 requests within a few blocks lately.');

        config(['neighborhoods.dc311' => false]);
        Livewire::withoutLazyLoading()->test(StationLive::class, ['code' => 'A05'])->assertSee('DC 311 data isn’t available right now.');

        Livewire::withoutLazyLoading()->test(StationLive::class, ['code' => 'K04'])->assertDontSee('Nearby service requests')->assertDontSee('Pepco');
    }

    public function test_ward_explorer_needs_a_post_in_every_ward(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 7) as $ward) {
            Dispatch::factory()->for($user)->create(['stop' => Neighborhoods::stationsInWard($ward)[0]]);
        }
        $this->assertArrayNotHasKey('ward-explorer', Achievements::unlocked($user));

        Dispatch::factory()->for($user)->create(['stop' => 'F06']); // Anacostia, Ward 8
        $this->assertArrayHasKey('ward-explorer', Achievements::unlocked($user->fresh()));
        $this->assertSame('DC', Stations::jurisdiction('F06'));
    }
}
