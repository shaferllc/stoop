<?php

namespace Tests\Feature;

use App\Livewire\Admin\Areas;
use App\Models\Area;
use App\Models\Dispatch;
use App\Models\Place;
use App\Models\User;
use App\Support\AreaReports;
use App\Support\ReaderInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AreaReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutVite();
        config(['services.anthropic.key' => null, 'services.openai.key' => null]);
        $this->travelTo(Carbon::parse('2026-10-15 12:00', config('stoop.timezone')));
    }

    protected function area(): Area
    {
        return Area::where('slug', 'noma-bid')->firstOrFail();
    }

    protected function sales(): User
    {
        return User::factory()->create(['is_staff' => true, 'roles' => ['sales']]);
    }

    /**
     * $people readers each post once at NoMa this month on the line given.
     */
    protected function posts(int $people, string $line = 'red', string $body = 'Great new spot, love it'): void
    {
        foreach (User::factory()->count($people)->create() as $user) {
            Dispatch::factory()->for($user)->create(['stop' => 'B35', 'line' => $line, 'body' => $body, 'status' => 'approved']);
        }
    }

    public function test_the_bids_are_seeded_as_station_groups_marked_verify(): void
    {
        $this->assertSame(9, Area::count());
        $this->assertTrue(Area::where('verify', false)->doesntExist());
        $this->assertSame(['B35', 'B03'], $this->area()->stations);
        $this->assertNotNull(Area::where('slug', 'anacostia-bid')->first());
    }

    public function test_cells_from_fewer_than_ten_people_are_suppressed(): void
    {
        $this->posts(12, 'red');
        $this->posts(4, 'orange', 'Terrible delay again');
        // Staff and bots never count.
        Dispatch::factory()->for(User::factory()->create(['is_staff' => true]))->count(20)->create(['stop' => 'B35', 'line' => 'blue']);

        $place = Place::create(['name' => 'Union Market', 'category' => 'Market', 'stop' => 'B35']);
        foreach (User::factory()->count(3)->create() as $user) {
            $been = Dispatch::factory()->for($user)->create(['type' => 'been', 'place_id' => $place->id, 'stop' => 'B35', 'line' => 'red']);
            DB::table('marks')->insert(['user_id' => $user->id, 'dispatch_id' => $been->id, 'kind' => 'been', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([650000, 720000] as $i => $price) {
            DB::table('home_sales')->insert(['ssl' => "0001 000$i", 'block' => '100 block of M St NE', 'price' => $price, 'sold_on' => '2026-10-02', 'stop' => 'B35', 'meters' => 100, 'created_at' => now(), 'updated_at' => now()]);
        }

        $report = AreaReports::report($this->area(), '2026-10');

        $this->assertSame(19, $report['totals']['people']);
        $this->assertSame(['Food'], array_column($report['topics'], 'topic'));
        $this->assertSame(ReaderInsights::SUPPRESSED, $report['checkins'][0]['checkins']);
        $this->assertSame(ReaderInsights::SUPPRESSED, $report['home_sales'][0]['median_price']);
        $this->assertSame(ReaderInsights::SUPPRESSED, $report['home_sales'][0]['sales']);
        $this->assertSame(ReaderInsights::SUPPRESSED, $report['events'][0]['rsvps']);
        $this->assertSame(['month' => 'Oct 2026', 'posts' => 19, 'people' => 19], end($report['trend']));
        $this->assertSame(ReaderInsights::SUPPRESSED, $report['trend'][0]['people']);

        // Nobody's name, email or post text appears anywhere in the report.
        $flat = json_encode($report);
        foreach (User::where('is_staff', false)->get() as $user) {
            $this->assertStringNotContainsString($user->username, $flat);
            $this->assertStringNotContainsString($user->email, $flat);
        }
        $this->assertStringNotContainsString('Terrible delay', $flat);

        // A quiet month is all suppressed.
        $quiet = AreaReports::report($this->area(), '2026-09');
        $this->assertSame(ReaderInsights::SUPPRESSED, $quiet['totals']['posts']);
        $this->assertSame([], $quiet['topics']);
    }

    public function test_csv_and_print_for_sales_only(): void
    {
        $this->posts(10);
        $area = $this->area();
        $sales = $this->sales();

        $this->actingAs($sales)->get(route('admin.areas'))->assertOk()->assertSee('Georgetown BID')->assertSee('VERIFY');
        $this->actingAs($sales)->get(route('admin.areas.print', [$area, '2026-10']))->assertOk()->assertSee('NoMa BID')->assertSee('VERIFY');
        $csv = $this->actingAs($sales)->get(route('admin.areas.csv', [$area, '2026-10']))->assertOk()->getContent();
        $this->assertStringContainsString('"Stoop area report","NoMa BID","October 2026"', $csv);

        foreach (['moderator', 'editor'] as $role) {
            $staff = User::factory()->create(['is_staff' => true, 'roles' => [$role]]);
            $this->actingAs($staff)->get(route('admin.areas'))->assertForbidden();
            $this->actingAs($staff)->get(route('admin.areas.print', [$area, '2026-10']))->assertForbidden();
            $this->actingAs($staff)->get(route('admin.areas.csv', [$area, '2026-10']))->assertForbidden();
        }
        $this->actingAs(User::factory()->create())->get(route('admin.areas'))->assertForbidden();
    }

    public function test_share_links_are_signed_and_expire(): void
    {
        $this->posts(10);
        $area = $this->area();

        $page = Livewire::actingAs($this->sales())->test(Areas::class)->set('month', '2026-10')->set('days', 7)->call('share', $area->id);
        $link = $page->get('links')[$area->id];
        auth()->logout();

        $this->get($link)->assertOk()->assertSee('NoMa BID')->assertDontSee('VERIFY:');

        // Changing the month or area breaks the signature.
        $this->get(str_replace('/2026-10?', '/2026-09?', $link))->assertForbidden();
        $this->get(str_replace("/areas/{$area->id}/", '/areas/1/', $link))->assertForbidden();
        $this->get(strtok($link, '?'))->assertForbidden();

        $this->travel(8)->days();
        $this->get($link)->assertForbidden();
    }
}
