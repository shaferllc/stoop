<?php

namespace Tests\Feature;

use App\Models\Dispatch;
use App\Models\User;
use App\Support\Achievements;
use App\Support\Stations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MetroGameTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_system_has_98_stations_and_every_line_stop_exists(): void
    {
        $this->assertCount(98, config('metro.stations'));
        foreach (config('metro.lines') as $line => $stops) {
            $this->assertSame([], array_diff($stops, array_keys(config('metro.stations'))), "$line line");
        }
        $this->assertSame(['red', 'green'], Stations::linesAt('B06'));
    }

    public function test_posting_from_stations_fills_the_map_and_earns_line_and_region_badges(): void
    {
        $user = User::factory()->create();
        foreach (config('metro.lines.yellow') as $code) {
            Dispatch::factory()->for($user)->create(['stop' => $code]);
        }
        Dispatch::factory()->for($user)->create(['stop' => 'F06']);

        $badges = Achievements::unlocked($user);
        $this->assertArrayHasKey('line-yellow', $badges);
        $this->assertArrayHasKey('crossed-the-river', $badges);
        $this->assertArrayNotHasKey('tri-state', $badges, 'Yellow covers DC and Virginia but not Maryland.');

        Dispatch::factory()->for($user)->create(['stop' => 'B08']);
        $this->assertArrayHasKey('tri-state', Achievements::unlocked($user->fresh()));
    }

    public function test_the_most_helpful_person_at_a_stop_is_its_mayor(): void
    {
        [$maya, $jordan] = User::factory()->count(2)->create();
        Dispatch::factory()->count(3)->for($maya)->create(['stop' => 'E04']);
        $post = Dispatch::factory()->for($maya)->create(['stop' => 'E04']);
        Dispatch::factory()->count(2)->for($jordan)->create(['stop' => 'E04']);
        Dispatch::factory()->for($jordan)->create(['parent_id' => $post->id, 'stop' => null]);
        Cache::forget('stoop:mayors');

        $this->assertSame($maya->id, Stations::mayorOf('E04')?->id);
        $this->assertSame(['E04'], Stations::mayorships($maya));
        $this->assertArrayHasKey('mayor', Achievements::unlocked($maya));

        $this->get('/?stop=E04')->assertSee('Mayor:')->assertSee('@'.$maya->username);
        $this->get(route('users.show', $maya))->assertSee('Mayor of')->assertSee('The system');
    }
}
