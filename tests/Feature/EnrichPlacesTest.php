<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Support\MagazineBackCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnrichPlacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_places_get_neighborhood_details_and_a_station_from_their_page(): void
    {
        config(['stoop.magazine_feed' => 'https://washingtonian.com/feed/']);
        $page = '<h1>Albi</h1><p>Navy Yard</p><span>•</span><p>Palestinian</p><span>•</span><p>$$$</p>'
            .'<p>Long review text that must not be stored.</p><h3>Locations</h3><p>1346 4th St SE,</p><p>Washington</p><p>DC,</p>'
            .'<h3>Website</h3><a>https://www.albidc.com/</a>';
        Http::preventStrayRequests();
        Http::fake(['washingtonian.com/robots.txt' => Http::response("User-agent: *\nDisallow: /wp-admin/"), 'washingtonian.com/*' => Http::response($page)]);
        $place = Place::create(['name' => 'Albi', 'category' => 'Restaurant', 'list_name' => '100 Very Best Restaurants 2026', 'article_url' => 'https://washingtonian.com/100-very-best-restaurants-2026/albi-4/']);

        $result = MagazineBackCatalog::enrichPlaces();

        $place->refresh();
        $this->assertSame(['checked' => 1, 'stations' => 1], $result);
        $this->assertSame('F05', $place->stop);
        $this->assertSame('Navy Yard', $place->neighborhood);
        $this->assertSame('Palestinian', $place->category);
        $this->assertSame('1346 4th St SE, Washington, DC', $place->address);
        $this->assertSame('https://www.albidc.com/', $place->website);
        $this->assertStringNotContainsString('review', (string) $place->blurb);
    }
}
