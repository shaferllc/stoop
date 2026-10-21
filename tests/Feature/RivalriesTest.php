<?php

namespace Tests\Feature;

use App\Models\Dispatch;
use App\Support\Newsletter;
use App\Support\Rivalries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RivalriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_matchups_rotate_and_the_winner_gets_a_crown(): void
    {
        $lastWeek = Rivalries::weekStart()->subWeek();
        [$a, $b] = Rivalries::pairsFor($lastWeek)[0];

        Dispatch::factory()->count(3)->create(['stop' => $a, 'created_at' => $lastWeek->addDays(2)->utc()]);
        Dispatch::factory()->create(['stop' => $b, 'created_at' => $lastWeek->addDays(2)->utc()]);

        $this->assertSame($a, Rivalries::matchups($lastWeek)->first()['leader']);
        $this->assertNotSame(Rivalries::pairsFor($lastWeek), Rivalries::pairsFor(Rivalries::weekStart()), 'Matchups change each week.');

        $this->get('/?stop='.$a)->assertSee('Won last week’s rivalry');
        $this->get(route('rivalries'))->assertOk()->assertSee(config('stoop.stations.'.$a).' won');
        $this->assertTrue(Newsletter::digest()['rivalries']->isNotEmpty());
    }

    public function test_line_standings_count_this_weeks_help(): void
    {
        Dispatch::factory()->count(2)->create(['stop' => 'F05', 'created_at' => now()]);

        $standings = Rivalries::lineStandings();
        $this->assertSame('green', $standings->keys()->first());
        $this->assertSame(2, $standings['green']['points']);
    }

    public function test_results_survive_a_real_cache_round_trip(): void
    {
        // The test suite's array cache keeps objects; real caches serialize them.
        config(['cache.default' => 'file']);
        Cache::flush();
        $lastWeek = Rivalries::weekStart()->subWeek();

        Rivalries::matchups($lastWeek);
        Rivalries::lineStandings();

        $this->assertCount(Rivalries::PER_WEEK, Rivalries::matchups($lastWeek));
        $this->assertCount(6, Rivalries::lineStandings());
        $this->get('/?stop=E05')->assertOk();
        Cache::flush();
    }
}
