<?php

namespace Tests\Feature;

use App\Livewire\StationLive;
use App\Support\Metro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class StationLiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_station_has_coordinates(): void
    {
        $this->assertSame([], array_diff(array_keys(config('metro.stations')), array_keys(config('metro_coords'))));
    }

    public function test_next_trains_ask_for_every_level_of_a_transfer_station(): void
    {
        config(['services.wmata.key' => 'test']);
        Http::fake(['api.wmata.com/*' => Http::response(['Trains' => [
            ['Line' => 'RD', 'Destination' => 'Shady Grove', 'Min' => '3'],
            ['Line' => 'BL', 'Destination' => 'Largo', 'Min' => 'ARR'],
            ['Line' => '--', 'Destination' => 'No Passenger', 'Min' => ''],
        ]])]);

        $trains = Metro::nextTrains('A01');

        $this->assertCount(2, $trains);
        $this->assertSame('red', $trains[0]['line']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'GetPrediction/A01,C01'));
    }

    public function test_bikeshare_lists_nearby_docks_that_are_renting(): void
    {
        Cache::flush();
        [$lat, $lon] = config('metro_coords.E04');
        Http::fake([
            '*/station_information.json' => Http::response(['data' => ['stations' => [
                ['station_id' => 'near', 'name' => '14th & Irving St NW', 'lat' => $lat + 0.001, 'lon' => $lon],
                ['station_id' => 'closed', 'name' => 'Closed dock', 'lat' => $lat, 'lon' => $lon],
                ['station_id' => 'far', 'name' => 'Far away', 'lat' => $lat + 0.05, 'lon' => $lon],
            ]]]),
            '*/station_status.json' => Http::response(['data' => ['stations' => [
                ['station_id' => 'near', 'num_bikes_available' => 5, 'num_docks_available' => 9, 'is_renting' => true, 'vehicle_types_available' => [['vehicle_type_id' => '2', 'count' => 2]]],
                ['station_id' => 'closed', 'num_bikes_available' => 0, 'num_docks_available' => 0, 'is_renting' => false],
                ['station_id' => 'far', 'num_bikes_available' => 9, 'num_docks_available' => 1, 'is_renting' => true],
            ]]]),
        ]);

        $docks = Metro::bikeshare('E04');

        $this->assertSame(['14th & Irving St NW'], array_column($docks, 'name'));
        $this->assertSame(2, $docks[0]['ebikes']);
        Livewire::withoutLazyLoading()->test(StationLive::class, ['code' => 'E04'])->assertSee('14th & Irving St NW')->assertSee('DC 311');
    }
}
