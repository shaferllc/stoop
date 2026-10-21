<?php

namespace Tests\Feature;

use App\Actions\FileDispatch;
use App\Bots\Homes;
use App\Livewire\Admin\RealEstate;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\HomeSales;
use App\Support\Stations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RealEstateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['stoop.model_moderation' => false, 'services.openai.key' => null, 'services.anthropic.key' => null]);
        Http::preventStrayRequests();
    }

    /**
     * A small square lot outline (lng, lat) around a point offset from a station.
     */
    protected function lotNear(string $code, float $dLat = 0.001, float $dLng = 0.0): array
    {
        [$lat, $lng] = config("metro_coords.$code");
        $lat += $dLat;
        $lng += $dLng;

        return [[$lng - 0.0001, $lat - 0.0001], [$lng + 0.0001, $lat - 0.0001], [$lng + 0.0001, $lat + 0.0001], [$lng - 0.0001, $lat + 0.0001]];
    }

    protected function sale(string $stop, int $price, int $daysAgo = 5, string $block = '1400 block of Q St NW'): void
    {
        static $n = 0;
        DB::table('home_sales')->insert([
            'ssl' => 'TEST '.++$n, 'block' => $block, 'price' => $price, 'stop' => $stop, 'meters' => 300,
            'sold_on' => now(config('stoop.timezone'))->subDays($daysAgo)->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_addresses_round_to_the_block(): void
    {
        $this->assertSame('1400 block of Q St NW', HomeSales::block('1428 Q ST NW'));
        $this->assertSame('3300 block of 18th St NW', HomeSales::block('3350 18TH ST NW, Unit 4'));
        $this->assertSame('Unit block of M St SE', HomeSales::block('42 M St SE'));
        $this->assertSame('1400 block of Q St NW', HomeSales::block('1428-A Q St nw'));
        $this->assertSame('Martin Luther King Jr Ave SE', HomeSales::block('MARTIN LUTHER KING JR AVE SE'));
    }

    public function test_sales_match_the_nearest_station_within_a_kilometer(): void
    {
        [$lat, $lng] = config('metro_coords.E02');
        $this->assertSame('E02', HomeSales::nearest($lat + 0.002, $lng)[0]);
        $this->assertEqualsWithDelta(222, HomeSales::nearest($lat + 0.002, $lng)[1], 5);
        $this->assertNull(HomeSales::nearest(39.5, -77.6));

        // Transfer stations match their main code, the one station pages use.
        [$lat, $lng] = config('metro_coords.B06');
        $this->assertSame('B06', HomeSales::nearest($lat, $lng)[0]);
    }

    public function test_import_stores_blocks_near_stations_and_never_owner_names(): void
    {
        $ms = now(config('stoop.timezone'))->subDays(10)->startOfDay()->getTimestampMs();
        Http::fake([HomeSales::SOURCE.'*' => Http::response(['features' => [
            ['attributes' => ['SSL' => '0442    0101', 'LOWNUMBER' => '1428', 'STREETNAME' => '7TH ST', 'QDRNTNAME' => 'NW', 'SALEPRICE' => 725000.0, 'SALEDATE' => $ms, 'OWNERNAME' => 'JANE SAMPLE'], 'geometry' => ['rings' => [$this->lotNear('E02')]]],
            ['attributes' => ['SSL' => '9999    0001', 'LOWNUMBER' => '100', 'STREETNAME' => 'FAR RD', 'QDRNTNAME' => 'NE', 'SALEPRICE' => 500000.0, 'SALEDATE' => $ms], 'geometry' => ['rings' => [[[-77.6, 39.5], [-77.6, 39.51], [-77.59, 39.5]]]]],
        ], 'exceededTransferLimit' => false])]);

        $this->assertSame(1, HomeSales::import());
        $this->assertSame(1, HomeSales::import());

        $this->assertSame(1, DB::table('home_sales')->count());
        $sale = DB::table('home_sales')->first();
        $this->assertSame('E02', $sale->stop);
        $this->assertSame('1400 block of 7th St NW', $sale->block);
        $this->assertSame(725000, (int) $sale->price);
        $this->assertSame(now(config('stoop.timezone'))->subDays(10)->toDateString(), $sale->sold_on);
        $this->assertStringNotContainsString('JANE', json_encode($sale));
        $this->assertStringNotContainsString('1428', json_encode($sale));
        Http::assertSent(fn (Request $r) => ! str_contains($r->url(), 'OWNER') && str_contains(urldecode($r->url()), "ACCEPTCODE = 'Market Sale'"));
    }

    public function test_a_failed_import_keeps_what_is_there(): void
    {
        $this->sale('E02', 600000);
        Http::fake([HomeSales::SOURCE.'*' => Http::response('down', 500)]);

        $this->assertSame(0, HomeSales::import());
        $this->assertSame(1, DB::table('home_sales')->count());
    }

    public function test_station_pages_show_recent_sales_with_the_median(): void
    {
        $this->sale('E02', 500000, 3, '1400 block of 7th St NW');
        $this->sale('E02', 700000, 4);
        $this->sale('E02', 900000, 5);
        $this->sale('E02', 2000000, 120);

        $this->get(route('home', ['stop' => 'E02']))
            ->assertSee('Recent sales near')
            ->assertSee('$700,000')
            ->assertSee('1400 block of 7th St NW')
            ->assertDontSee('$2,000,000');

        $this->get(route('home', ['stop' => 'A01']))->assertDontSee('Recent sales near');
    }

    public function test_homes_bot_posts_a_weekly_roundup_for_busy_stations(): void
    {
        foreach ([400000, 500000, 600000, 700000, 800000] as $price) {
            $this->sale('E02', $price);
        }
        $this->sale('A01', 999000);
        $bot = new Homes;

        $this->assertSame(1, $bot->roundup());
        $post = Dispatch::where('user_id', $bot->account()->id)->sole();
        $this->assertSame('green', $post->line);
        $this->assertSame('E02', $post->stop);
        $this->assertStringContainsString('Homes near '.Stations::name('E02').' sold for a median $600,000 this month', $post->body);

        $this->assertSame(0, $bot->roundup());
        DB::table('home_sales')->delete();
        $this->assertSame(0, $bot->roundup());
    }

    public function test_verified_people_post_open_houses_on_the_green_line(): void
    {
        $user = User::factory()->create();
        $date = now(config('stoop.timezone'))->addDays(3)->toDateString();

        $dispatch = FileDispatch::handle($user, [
            'type' => 'openhouse', 'line' => 'red', 'stop' => 'E02', 'body' => 'Sunny rowhouse with a garden.',
            'openHouse' => ['block' => '1428 Q St NW', 'date' => $date, 'from' => '13:00', 'to' => '16:00', 'price' => '725000', 'url' => 'https://example.com/listing', 'agent' => 'Sam Agent, Sample Realty'],
        ]);

        $this->assertSame('green', $dispatch->line);
        $this->assertSame('1400 block of Q St NW', $dispatch->details['block']);
        $this->assertSame(725000, $dispatch->details['price']);
        $this->assertSame('Sam Agent, Sample Realty', $dispatch->details['agent']);
        $this->assertArrayNotHasKey('sponsored', $dispatch->details);

        $this->get(route('home', ['line' => 'green', 'tab' => 'latest']))->assertSee('1400 block of Q St NW');
        $this->get(route('home', ['stop' => 'E02', 'tab' => 'latest']))
            ->assertSee('Listing agent:')->assertSee('$725,000')->assertDontSee('1428 Q St');

        try {
            FileDispatch::handle($user, ['type' => 'openhouse', 'body' => 'No stop given here.', 'openHouse' => ['block' => '1 A St NE', 'date' => $date, 'from' => '13:00', 'to' => '12:00']]);
            $this->fail('Expected validation to fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stop', $e->errors());
            $this->assertArrayHasKey('openHouse.to', $e->errors());
        }

        $this->expectException(ValidationException::class);
        FileDispatch::handle(User::factory()->unverified()->create(), [
            'type' => 'openhouse', 'stop' => 'E02', 'body' => 'Unverified open house.',
            'openHouse' => ['block' => '1428 Q St NW', 'date' => $date, 'from' => '13:00', 'to' => '16:00'],
        ]);
    }

    public function test_only_staff_mark_open_houses_sponsored(): void
    {
        $dispatch = Dispatch::factory()->create(['type' => 'openhouse', 'line' => 'green', 'stop' => 'E02', 'details' => ['block' => '1400 block of Q St NW', 'date' => now()->addDay()->toDateString(), 'from' => '13:00', 'to' => '16:00', 'url' => 'https://example.com/l']]);

        Livewire::actingAs(User::factory()->create())->test(RealEstate::class)->assertForbidden();

        Livewire::actingAs(User::factory()->create(['is_staff' => true]))->test(RealEstate::class)
            ->assertSee('1400 block of Q St NW')
            ->call('sponsor', $dispatch->id, true);

        $this->assertTrue($dispatch->fresh()->details['sponsored']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'openhouse.sponsored']);
        $this->get(route('dispatches.show', $dispatch))->assertSee('Sponsored')->assertSee('rel="sponsored noopener"', false);
    }
}
