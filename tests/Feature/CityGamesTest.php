<?php

namespace Tests\Feature;

use App\Livewire\Admin\Hunts;
use App\Livewire\Pages\HuntShow;
use App\Livewire\Pages\MetroBingo;
use App\Models\Dispatch;
use App\Models\Hunt;
use App\Models\Place;
use App\Models\User;
use App\Support\Achievements;
use App\Support\Bingo;
use App\Support\Passports;
use App\Support\Rivalries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class CityGamesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function test_bingo_cards_are_stable_per_person_and_week(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $week = Bingo::weekStart();

        $card = Bingo::layout($a, $week);
        $this->assertCount(25, $card);
        $this->assertSame(Bingo::FREE, $card[12]);
        $this->assertSame($card, Bingo::layout($a, $week));
        $this->assertNotSame($card, Bingo::layout($b, $week));
        $this->assertNotSame($card, Bingo::layout($a, $week->addWeek()));

        $this->assertSame(0, Bingo::lines(array_fill(0, 25, false)));
        $this->assertSame(12, Bingo::lines(array_fill(0, 25, true)));
    }

    public function test_activity_marks_squares_and_hand_marks_need_a_post_as_proof(): void
    {
        $user = User::factory()->create();
        Dispatch::factory()->for($user)->create(['stop' => 'C05', 'type' => 'dispatch']); // Rosslyn: orange, blue, silver
        $card = collect(Bingo::card($user)['squares'])->keyBy('key');

        foreach (['line-orange', 'line-blue', 'line-silver'] as $key) {
            if ($card->has($key)) {
                $this->assertTrue($card[$key]['marked']);
            }
        }
        if ($card->has('line-red')) {
            $this->assertFalse($card['line-red']['marked']);
        }

        $manual = $card->first(fn ($sq) => ! $sq['auto']);
        $auto = $card->first(fn ($sq) => $sq['auto'] && $sq['key'] !== Bingo::FREE);
        $mine = $user->dispatches()->first();
        $someoneElses = Dispatch::factory()->create();

        $this->assertNotNull(Bingo::mark($user, $auto['key'], $mine->id));
        $this->assertNotNull(Bingo::mark($user, $manual['key'], $someoneElses->id));
        Livewire::actingAs($user)->test(MetroBingo::class)->call('startMarking', $manual['key'])->set('proofId', $mine->id)->call('mark')->assertHasNoErrors();
        $this->assertTrue(collect(Bingo::card($user)['squares'])->firstWhere('key', $manual['key'])['marked']);

        // Deleting the proof unmarks the square.
        $mine->delete();
        $this->assertFalse(collect(Bingo::card($user)['squares'])->firstWhere('key', $manual['key'])['marked']);
    }

    public function test_a_bingo_awards_the_badge_and_xp(): void
    {
        $user = User::factory()->create();
        $proof = Dispatch::factory()->for($user)->create(['stop' => null]);
        $layout = Bingo::layout($user, Bingo::weekStart());
        $before = Achievements::xp($user->fresh());

        // The middle row: hand-mark every square in it, faking any self-marking ones.
        foreach ([10, 11, 13, 14] as $i) {
            \DB::table('bingo_marks')->insert(['user_id' => $user->id, 'week' => Bingo::weekStart()->toDateString(), 'square' => $layout[$i], 'dispatch_id' => $proof->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertSame(1, Bingo::wins($user));
        $this->assertArrayHasKey('bingo', Achievements::unlocked($user->fresh()));
        $this->assertSame($before + Bingo::XP, Achievements::xp($user->fresh()));
        Livewire::actingAs($user)->test(MetroBingo::class)->assertSee('Bingo!');

        Sanctum::actingAs($user, ['app']);
        $this->getJson('/api/app/bingo')->assertOk()->assertJsonPath('data.lines', 1)->assertJsonCount(25, 'data.squares');
    }

    public function test_editors_create_hunts_and_players_check_in_for_stamps(): void
    {
        $staff = User::factory()->create(['is_staff' => true]);
        $place = Place::create(['name' => 'Ben’s Chili Bowl', 'stop' => 'E03']);
        $tz = 'America/New_York';

        Livewire::actingAs(User::factory()->create())->test(Hunts::class)->assertForbidden();
        Livewire::actingAs($staff)->test(Hunts::class)
            ->set('hunt.name', 'Fall Hunt')->set('hunt.starts_at', now($tz)->subDay()->format('Y-m-d\TH:i'))->set('hunt.ends_at', now($tz)->addWeek()->format('Y-m-d\TH:i'))
            ->set('steps.0.clue', 'Where the half-smoke was born')->set('steps.0.place_id', $place->id)->set('steps.0.code_word', 'Chili')
            ->call('addStep')->set('steps.1.clue', 'Escalators that go on forever')->set('steps.1.stop', 'E05')
            ->call('create')->assertHasNoErrors();

        $hunt = Hunt::with('steps')->firstOrFail();
        $this->assertSame(2, $hunt->steps->count());
        $this->get(route('hunts'))->assertOk()->assertSee('Fall Hunt');
        $this->actingAs($staff)->get(route('admin.hunts'))->assertOk()->assertSee('Chili');
        $this->assertDatabaseHas('audit_logs', ['action' => 'hunt.created']);

        $player = User::factory()->create();
        [$first, $second] = $hunt->steps;

        // The page shows clues, never answers or code words.
        Livewire::actingAs($player)->test(HuntShow::class, ['hunt' => $hunt])->assertSee('Where the half-smoke was born')->assertDontSee('Chili')->assertDontSee('E05');

        $page = Livewire::actingAs($player)->test(HuntShow::class, ['hunt' => $hunt]);
        $page->call('checkIn', $second->id)->assertHasErrors("codes.{$second->id}"); // no post there yet
        Dispatch::factory()->for($player)->create(['stop' => 'E05', 'type' => 'dispatch']);
        $page->call('checkIn', $second->id)->assertHasNoErrors();

        Dispatch::factory()->for($player)->create(['stop' => 'E03', 'type' => 'been', 'place_id' => $place->id]);
        $page->set("codes.{$first->id}", 'wrong')->call('checkIn', $first->id)->assertHasErrors("codes.{$first->id}");
        $page->set("codes.{$first->id}", ' chili ')->call('checkIn', $first->id)->assertHasNoErrors();

        $this->assertCount(2, Passports::huntStamps($player));
        $this->assertArrayHasKey('hunter', Achievements::unlocked($player->fresh()));
        $this->assertSame($player->id, $hunt->leaderboard()->first()->user->id);

        Sanctum::actingAs($player, ['app']);
        $json = $this->getJson("/api/app/hunts/{$hunt->slug}")->assertOk()->assertJsonPath('data.leaderboard.0.steps', 2)->getContent();
        $this->assertStringNotContainsString('Chili', $json);
        $this->assertStringNotContainsString('E05', $json);
    }

    public function test_line_cup_scores_lines_and_crowns_the_winners_regulars(): void
    {
        $this->travelTo(now('America/New_York')->startOfMonth()->addDays(10)->utc());
        $lastMonth = Rivalries::monthStart()->subMonthNoOverflow();
        [$regular, $casual] = User::factory()->count(2)->create(['created_at' => $lastMonth->subMonth()]);

        // Glenmont (B11) is only on the Red Line.
        foreach (range(1, 5) as $i) {
            Dispatch::factory()->for($regular)->create(['stop' => 'B11', 'created_at' => $lastMonth->addDays($i)->utc()]);
        }
        Dispatch::factory()->for($casual)->create(['stop' => 'B11', 'created_at' => $lastMonth->addDays(2)->utc()]);

        $cup = Rivalries::lineCup($lastMonth);
        $this->assertSame('red', $cup->keys()->first());
        $this->assertSame(6, $cup['red']['points']);
        $this->assertSame([$regular->id], $cup['red']['regulars']);
        $this->assertSame('red', Rivalries::cupWinner($lastMonth));

        $this->assertArrayHasKey('line-cup', Achievements::unlocked($regular->fresh()));
        $this->assertArrayNotHasKey('line-cup', Achievements::unlocked($casual->fresh()));

        $this->get(route('line-cup'))->assertOk()->assertSee('Line Cup')->assertSee('red line');
        Sanctum::actingAs($casual, ['app']);
        $this->getJson('/api/app/line-cup')->assertOk()->assertJsonPath('data.winners.0.line', 'red');
    }
}
