<?php

namespace Tests\Feature;

use App\Livewire\Pages\Feed;
use App\Models\Dispatch;
use App\Models\Place;
use App\Models\User;
use App\Support\Achievements;
use App\Support\Passports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HelpfulnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_asker_picks_a_best_answer_and_the_answerer_gets_points(): void
    {
        [$asker, $helper, $other] = User::factory()->count(3)->create();
        $question = Dispatch::factory()->for($asker)->create(['type' => 'ask', 'line' => 'yellow', 'body' => 'Quick lunch near McPherson?']);
        $answer = Dispatch::factory()->for($helper)->create(['parent_id' => $question->id, 'body' => 'The banh mi spot on 15th']);
        Dispatch::factory()->for($other)->create(['parent_id' => $question->id, 'body' => 'Salad place']);
        $xpBefore = Achievements::xp($helper);

        Livewire::actingAs($other)->test(Feed::class)->call('pickBestAnswer', $answer->id)->assertForbidden();
        Livewire::actingAs($asker)->test(Feed::class)->call('pickBestAnswer', $answer->id);

        $this->assertSame($answer->id, $question->fresh()->best_answer_id);
        $this->assertSame('best', $helper->notifications()->first()->data['kind']);
        $this->assertSame($xpBefore + 25, Achievements::xp($helper->fresh()));
        $this->get(route('dispatches.show', $question))->assertSeeInOrder(['Best answer', 'The banh mi spot on 15th', 'Salad place']);
    }

    public function test_five_best_answers_make_a_know_it_all(): void
    {
        $helper = User::factory()->create();
        foreach (range(1, 5) as $i) {
            $q = Dispatch::factory()->create(['type' => 'ask']);
            $a = Dispatch::factory()->for($helper)->create(['parent_id' => $q->id]);
            $q->forceFill(['best_answer_id' => $a->id])->save();
        }

        $this->assertArrayHasKey('know-it-all', Achievements::unlocked($helper));
    }

    public function test_ten_been_heres_on_a_list_earn_its_stamp(): void
    {
        $user = User::factory()->create();
        $places = collect(range(1, 12))->map(fn ($i) => Place::create(['name' => "Sample Spot $i", 'list_name' => 'Cheap Eats', 'list_rank' => $i]));

        foreach ($places->take(9) as $place) {
            $post = Dispatch::factory()->create(['place_id' => $place->id, 'type' => 'been']);
            $user->marked()->attach($post->id, ['kind' => 'been']);
        }
        $this->assertNull(Passports::for($user)->first()['stamped_at']);

        $post = Dispatch::factory()->create(['place_id' => $places[9]->id, 'type' => 'been']);
        $user->marked()->attach($post->id, ['kind' => 'been']);

        $this->assertNotNull(Passports::for($user)->first()['stamped_at']);
        $this->assertArrayHasKey('passport', Achievements::unlocked($user));
        $this->assertSame($user->id, Passports::recentStamps()->first()['user']->id);
        $this->actingAs($user)->get(route('passports'))->assertOk()->assertSee('Stamped')->assertSee('10 / 10');
    }
}
