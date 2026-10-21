<?php

namespace Tests\Feature;

use App\Livewire\Admin\Insights;
use App\Models\Dispatch;
use App\Models\Place;
use App\Models\User;
use App\Support\ReaderInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ReaderInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => null]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        ReaderInsights::fake(null);
        parent::tearDown();
    }

    /**
     * @return Collection<int, User>
     */
    protected function people(int $count, array $attributes = []): Collection
    {
        return User::factory()->count($count)->create($attributes);
    }

    protected function report(): array
    {
        return ReaderInsights::report(now()->subDays(30), now()->addMinute());
    }

    public function test_cells_from_fewer_than_ten_people_are_suppressed(): void
    {
        foreach ($this->people(10) as $person) {
            Dispatch::factory()->create(['user_id' => $person->id, 'stop' => 'E02', 'line' => 'green', 'body' => 'I love the new bakery here, great bread']);
        }
        // Lots of posts from only three people.
        foreach ($this->people(3) as $person) {
            Dispatch::factory()->count(20)->create(['user_id' => $person->id, 'stop' => 'A01', 'line' => 'red', 'body' => 'Terrible delay again']);
        }
        // Staff, bots and pending posts never count.
        foreach ([...$this->people(5, ['is_staff' => true]), ...$this->people(5, ['is_bot' => true])] as $person) {
            Dispatch::factory()->create(['user_id' => $person->id, 'stop' => 'E02', 'line' => 'green']);
            Dispatch::factory()->create(['user_id' => $person->id, 'stop' => 'C01', 'line' => 'blue']);
        }
        Dispatch::factory()->create(['stop' => 'E02', 'status' => 'pending']);

        $report = $this->report();
        $stations = collect($report['stations'])->keyBy('station');

        $shaw = $stations[config('stoop.stations.E02')];
        $this->assertSame(10, $shaw['people']);
        $this->assertSame(10, $shaw['posts']);
        $this->assertSame('Real Estate', $shaw['top_topics']);
        $this->assertSame(100, $shaw['sentiment']);

        $metroCenter = $stations[config('stoop.stations.A01')];
        $this->assertSame(['<10', '<10', '<10', '<10'], [$metroCenter['posts'], $metroCenter['people'], $metroCenter['top_topics'], $metroCenter['sentiment']]);
        $this->assertFalse($stations->has(config('stoop.stations.C01')));

        $topics = collect($report['topics'])->keyBy('topic');
        $this->assertSame('<10', $topics['Food']['posts']);
        $this->assertSame(13, $report['totals']['people']);
        $this->assertSame(70, $report['totals']['posts']);
    }

    public function test_rising_keywords_need_ten_authors_and_hide_who_said_them(): void
    {
        $people = $this->people(12);
        foreach ($people as $i => $person) {
            Dispatch::factory()->create(['user_id' => $person->id, 'body' => 'The bakery on @'.$person->username.' street, 1428 Q St'.($i < 9 ? ' croissant' : '')]);
        }

        $keywords = collect($this->report()['keywords'])->keyBy('keyword');

        $this->assertSame(12, $keywords['bakery']['people']);
        $this->assertSame('<10', $keywords['bakery']['before']);
        $this->assertFalse($keywords->has('croissant'));
        $this->assertFalse($keywords->has(strtolower($people[0]->username)));

        $json = json_encode($this->report());
        $this->assertStringNotContainsString($people[0]->username, $json);
        $this->assertStringNotContainsString($people[0]->email, $json);
    }

    public function test_places_and_games_count_distinct_people(): void
    {
        $place = Place::create(['name' => 'Sample Diner', 'stop' => 'E02']);
        $quieter = Place::create(['name' => 'Sample Bar', 'stop' => 'E02']);
        $post = Dispatch::factory()->create(['type' => 'been', 'place_id' => $place->id]);
        $other = Dispatch::factory()->create(['type' => 'been', 'place_id' => $quieter->id]);

        foreach ($this->people(11) as $i => $person) {
            $person->marked()->attach($post->id, ['kind' => 'been']);
            if ($i < 4) {
                $person->marked()->attach($other->id, ['kind' => 'been']);
            }
            DB::table('quiz_attempts')->insert(['user_id' => $person->id, 'day' => now()->toDateString(), 'question_ids' => '[]', 'answers' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        }

        $report = $this->report();
        $places = collect($report['places'])->keyBy('place');
        $this->assertSame(11, $places['Sample Diner']['people']);
        $this->assertSame('<10', $places['Sample Bar']['people']);

        $games = collect($report['participation'])->keyBy('activity');
        $this->assertSame(11, $games['The Daily quiz']['people']);
        $this->assertSame('<10', $games['Polls']['people']);
    }

    public function test_the_report_is_staff_only(): void
    {
        $reader = User::factory()->create();
        Livewire::actingAs($reader)->test(Insights::class)->assertForbidden();
        $this->actingAs($reader)->get(route('admin.insights.csv'))->assertForbidden();
        $this->actingAs($reader)->get(route('admin.insights.print'))->assertForbidden();

        auth()->logout();
        $this->get(route('admin.insights'))->assertRedirect(route('login'));

        $staff = User::factory()->create(['is_staff' => true]);
        $csv = $this->actingAs($staff)->get(route('admin.insights.csv', ['from' => now()->subWeek()->toDateString(), 'to' => now()->toDateString()]));
        $csv->assertOk();
        $this->assertStringContainsString('Stoop reader insights', $csv->streamedContent());
        $this->actingAs($staff)->get(route('admin.insights.print'))->assertOk()->assertSee('Reader insights')->assertSee('Print or save as PDF');
        $this->actingAs($staff)->get(route('admin.insights'))->assertOk();
    }

    public function test_the_claude_summary_needs_a_key_and_sees_only_aggregates(): void
    {
        $staff = User::factory()->create(['is_staff' => true]);
        $author = User::factory()->create();
        Dispatch::factory()->create(['user_id' => $author->id, 'stop' => 'E02']);

        Livewire::actingAs($staff)->test(Insights::class)
            ->assertSee('Needs ANTHROPIC_API_KEY')
            ->assertDontSee('Write a summary with Claude');

        $seen = null;
        ReaderInsights::fake(function (string $numbers) use (&$seen) {
            $seen = $numbers;

            return 'Readers are most active around Shaw.';
        });

        Livewire::actingAs($staff)->test(Insights::class)
            ->call('summarize')
            ->assertSee('Readers are most active around Shaw.');

        $this->assertStringContainsString('"<10"', $seen);
        $this->assertStringNotContainsString($author->username, $seen);
        $this->assertStringNotContainsString($author->email, $seen);
    }
}
