<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ShowcaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShowcaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_every_feature_once(): void
    {
        $this->seed(DatabaseSeeder::class);
        $owner = User::factory()->create(['username' => 'tshafer']);
        DB::table('dispatches')->insert(['user_id' => $owner->id, 'type' => 'dispatch', 'line' => 'red', 'body' => 'Hello, Stoop.', 'status' => 'approved', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $ownerRow = (array) DB::table('users')->find($owner->id);

        $this->seed(ShowcaseSeeder::class);

        $this->assertSame(2, User::whereJsonContains('roles', 'editor')->count());
        $this->assertGreaterThan(0, User::whereJsonContains('roles', 'moderator')->count());
        $this->assertGreaterThan(0, User::whereJsonContains('roles', 'sales')->count());
        $this->assertGreaterThan(300, DB::table('dispatches')->count());
        $this->assertSame(1, DB::table('dispatches')->where('body', 'like', 'Perfect. I’m bringing my kid%')
            ->whereIn('parent_id', DB::table('dispatches')->whereIn('parent_id', DB::table('dispatches')->whereNotNull('parent_id')->select('id'))->select('id'))->count());
        $this->assertGreaterThan(5, DB::table('dispatches')->where('type', 'ask')->whereNotNull('best_answer_id')->count());
        $this->assertGreaterThan(0, DB::table('happenings')->whereNotNull('ticket_url')->where('featured', true)->count());
        $this->assertGreaterThan(0, DB::table('dispatches')->where('type', 'lost')->whereNotNull('details->reunited_at')->count());
        $this->assertSame(5, DB::table('dispatches')->where('type', 'openhouse')->count());
        $this->assertGreaterThan(0, DB::table('place_deals')->count());
        $this->assertGreaterThan(0, DB::table('bingo_marks')->count());
        $this->assertGreaterThan(0, DB::table('hunt_completions')->count());
        $this->assertGreaterThan(0, DB::table('question_votes')->count());
        $this->assertSame(2, DB::table('ballots')->count());
        $this->assertSame(1, DB::table('issues')->whereNotNull('dispatch_id')->count());
        $this->assertSame(8, DB::table('archive_stories')->count());
        $this->assertSame(7, DB::table('tips')->count());
        $this->assertSame(3, DB::table('dispatches')->where('status', 'held')->whereNotNull('screening->watch')->count());
        $this->assertSame(2, DB::table('spam_clusters')->where('status', 'open')->count());
        $this->assertSame(1, DB::table('appeals')->where('status', 'open')->count());
        $this->assertGreaterThan(0, DB::table('conversations')->where('is_group', true)->count());
        $this->assertGreaterThan(10, $owner->unreadNotifications()->count());
        $this->assertSame(1, DB::table('quote_requests')->where('status', 'pending')->count());
        $this->assertSame($ownerRow, (array) DB::table('users')->find($owner->id));

        // A second run changes nothing.
        $before = collect(['users', 'dispatches', 'marks', 'notifications', 'spam_clusters'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);
        $this->seed(ShowcaseSeeder::class);
        $this->assertEquals($before, collect($before)->map(fn ($n, $t) => DB::table($t)->count()));
    }
}
