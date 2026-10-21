<?php

namespace Tests\Feature;

use App\Livewire\Admin\AncSummaries;
use App\Livewire\Admin\Editorial;
use App\Livewire\Pages\AmaShow;
use App\Livewire\Pages\BallotShow;
use App\Models\Ballot;
use App\Models\Dispatch;
use App\Models\EditorThread;
use App\Models\Place;
use App\Models\User;
use App\Support\Claude;
use App\Support\Magazine;
use App\Support\Neighborhoods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class EditorialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => null, 'stoop.model_moderation' => false, 'services.openai.key' => null]);
    }

    protected function tearDown(): void
    {
        Claude::fake(null);
        parent::tearDown();
    }

    protected function staff(): User
    {
        return User::factory()->create(['is_staff' => true]);
    }

    public function test_magazine_articles_show_as_rich_cards_with_a_discuss_count(): void
    {
        config(['stoop.magazine_feed' => 'https://www.magazine.example/feed']);
        Http::fake([
            'www.magazine.example/feed' => Http::response('<?xml version="1.0"?><rss xmlns:media="http://search.yahoo.com/mrss/"><channel>
                <item><title>The 25 Best Cheap Eats</title><link>https://www.magazine.example/cheap-eats</link><guid>a1</guid><category>Food</category>
                    <media:content url="https://cdn.magazine.example/eats.jpg" medium="image" /></item>
                <item><title>Homes for sale in Petworth</title><link>https://www.magazine.example/petworth</link><guid>a2</guid><category>Real Estate</category>
                    <enclosure url="https://tracker.example/pixel.jpg" type="image/jpeg" /></item>
            </channel></rss>'),
            '*' => Http::response('', 404),
        ]);

        $this->assertSame(2, Magazine::import());

        $eats = Dispatch::where('body', 'like', '%Cheap Eats%')->first();
        $this->assertSame(['title' => 'The 25 Best Cheap Eats', 'section' => 'Food', 'image' => 'https://cdn.magazine.example/eats.jpg'], $eats->details['article']);
        $this->assertNull(Dispatch::where('body', 'like', '%Petworth%')->first()->details['article']['image'] ?? null, 'Images from other domains are dropped.');
        Dispatch::factory()->create(['parent_id' => $eats->id]);

        $this->get(route('dispatches.show', $eats))->assertOk()
            ->assertSee('https://cdn.magazine.example/eats.jpg')->assertSee('Discuss · 1')->assertSeeText(config('stoop.magazine_name').' · Food');
    }

    public function test_ask_the_editor_questions_upvotes_and_answers(): void
    {
        $editor = $this->staff();
        [$reader, $other] = User::factory()->count(2)->create();

        $this->actingAs($reader);
        Livewire::test(Editorial::class)->assertForbidden();

        $this->actingAs($editor);
        Livewire::test(Editorial::class)
            ->set('amaTitle', 'Our critic on new restaurants')->set('amaStarts', now()->subHour()->timezone('America/New_York')->format('Y-m-d\TH:i'))
            ->set('amaEnds', now()->addDay()->timezone('America/New_York')->format('Y-m-d\TH:i'))->call('openAma')->assertHasNoErrors();
        $thread = EditorThread::firstOrFail();
        $this->assertSame($thread->id, $thread->dispatch->details['ama']);
        $this->get('/?tab=latest')->assertSee('Ask the editor')->assertSee(route('ama.show', $thread), false);

        Livewire::actingAs($reader)->test(AmaShow::class, ['thread' => $thread])->set('question', 'Where is the best pho?')->call('ask')->assertHasNoErrors();
        Livewire::actingAs($other)->test(AmaShow::class, ['thread' => $thread])->set('question', 'Is brunch overrated?')->call('ask');
        $pho = Dispatch::where('body', 'Where is the best pho?')->first();
        $brunch = Dispatch::where('body', 'Is brunch overrated?')->first();

        Livewire::actingAs($other)->test(AmaShow::class, ['thread' => $thread])->call('upvote', $pho->id);
        $this->assertSame([$pho->id, $brunch->id], $thread->questions(null)->pluck('id')->all(), 'Most upvoted first.');

        Livewire::actingAs($reader)->test(AmaShow::class, ['thread' => $thread])->set('answers.'.$pho->id, 'Pho 75')->call('answer', $pho->id)->assertForbidden();
        Livewire::actingAs($editor)->test(AmaShow::class, ['thread' => $thread])->set('answers.'.$pho->id, 'Try Pho 75 in Arlington.')->call('answer', $pho->id);
        Livewire::test(AmaShow::class, ['thread' => $thread])->assertSeeInOrder(['Where is the best pho?', 'answered', 'Try Pho 75 in Arlington.', 'Is brunch overrated?']);

        // The app sees the same thread, and can upvote (but not its own question).
        Sanctum::actingAs($reader, ['app']);
        $this->getJson("/api/app/ask-the-editor/{$thread->id}")->assertOk()
            ->assertJsonPath('data.questions.0.votes', 1)->assertJsonPath('data.questions.0.answers.0.body', 'Try Pho 75 in Arlington.')
            ->assertJsonMissing(['email' => $reader->email]);
        $this->postJson("/api/app/ask-the-editor/{$thread->id}/questions/{$brunch->id}/vote")->assertJsonPath('data.voted', true);
        $this->postJson("/api/app/ask-the-editor/{$thread->id}/questions/{$pho->id}/vote")->assertStatus(422);
        $this->postJson("/api/app/ask-the-editor/{$thread->id}/questions", ['body' => 'Best bagel?'])->assertCreated();

        $thread->update(['ends_at' => now()->subMinute()]);
        $this->postJson("/api/app/ask-the-editor/{$thread->id}/questions", ['body' => 'Too late?'])->assertStatus(422);
        Livewire::actingAs($reader)->test(AmaShow::class, ['thread' => $thread])->set('question', 'Too late?')->call('ask')->assertHasErrors('question');
    }

    public function test_best_of_washington_ballots(): void
    {
        $staff = $this->staff();
        $pizza = collect(['Pizzeria Paradiso', 'Timber Pizza', 'All Purpose'])->map(fn ($name) => Place::create(['name' => $name]));
        [$alice, $bob] = User::factory()->count(2)->create();

        $this->actingAs($alice)->get('/admin/editorial')->assertForbidden();
        $this->actingAs($staff);
        Livewire::test(Editorial::class)->set('ballotTitle', 'Best of Washington')
            ->set('ballotOpens', now()->subHour()->timezone('America/New_York')->format('Y-m-d\TH:i'))->set('ballotCloses', now()->addWeek()->timezone('America/New_York')->format('Y-m-d\TH:i'))
            ->set('ballotCategories', 'Best pizza: Nowhere Pizza, timber-pizza')->call('createBallot')->assertHasErrors('ballotCategories')
            ->set('ballotCategories', 'Best pizza: Pizzeria Paradiso, timber-pizza, =All Purpose')->call('createBallot')->assertHasErrors('ballotCategories')
            ->set('ballotCategories', 'Best pizza: Pizzeria Paradiso, timber-pizza, All Purpose')->call('createBallot')->assertHasNoErrors();
        $ballot = Ballot::firstOrFail();
        $nominee = fn (Place $p) => DB::table('ballot_nominees')->where('place_id', $p->id)->value('id');

        Livewire::actingAs($alice)->test(BallotShow::class, ['ballot' => $ballot])->assertDontSee('votes')->call('vote', $nominee($pizza[1]));
        $this->assertSame('You already voted in this category.', $ballot->vote($alice, $nominee($pizza[0])));
        Sanctum::actingAs($bob, ['app']);
        $this->postJson("/api/app/ballots/{$ballot->id}/vote", ['nominee_id' => $nominee($pizza[1])])->assertOk();
        $this->getJson("/api/app/ballots/{$ballot->id}")->assertJsonPath('data.categories.0.my_pick', $nominee($pizza[1]))
            ->assertJsonPath('data.categories.0.nominees.0.votes', null);

        $this->actingAs($staff);
        Livewire::test(Editorial::class)->call('closeBallot', $ballot->id);
        $this->assertSame('Voting is closed.', $ballot->fresh()->vote(User::factory()->create(), $nominee($pizza[0])));
        Livewire::test(BallotShow::class, ['ballot' => $ballot])->assertSee('Winner: Timber Pizza')->assertSee('2 votes');

        $csv = $this->get(route('admin.ballots.csv', $ballot))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $this->assertStringContainsString('"Best pizza","1","Timber Pizza","","2"', $csv);
        $this->actingAs($alice)->get(route('admin.ballots.csv', $ballot))->assertForbidden();

        // Spreadsheet formulas in place names are neutralized.
        Place::first()->update(['name' => '=HYPERLINK("x")']);
        $this->assertStringContainsString("\"'=HYPERLINK(\"\"x\"\")\"", $ballot->fresh()->csv());
    }

    public function test_anc_summaries_are_previewed_then_published_by_a_person(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        // No key: drafting says so, but staff can still write one.
        Livewire::test(AncSummaries::class)->assertSee('Needs ANTHROPIC_API_KEY')
            ->set('anc', '1B')->set('notes', 'Agenda: liquor license for a new bar on U Street; protected bike lanes on 11th')->call('draft')->assertHasErrors('summary');

        Claude::fake(fn ($system, $user) => str_contains($user, '<notes>') ? 'The commission backed a liquor license for a new U Street bar and asked DDOT for 11th Street bike lanes. Next meeting Nov 5.' : null);
        $component = Livewire::test(AncSummaries::class)->set('anc', '1B')
            ->set('notes', 'Agenda: liquor license for a new bar on U Street; protected bike lanes on 11th')->call('draft')
            ->assertSet('summary', 'The commission backed a liquor license for a new U Street bar and asked DDOT for 11th Street bike lanes. Next meeting Nov 5.')
            ->assertSee('Preview')->assertSee('U Street, Columbia Hts');
        $this->assertSame(0, Dispatch::count(), 'Nothing posts from a draft.');

        $component->set('summary', 'Edited by a person: the commission backed a liquor license on U Street.')->call('publish')->assertHasNoErrors();
        $posts = Dispatch::all();
        $this->assertEqualsCanonicalizing(Neighborhoods::stationsInWard(1), $posts->pluck('stop')->all());
        $this->assertTrue($posts->every(fn ($d) => $d->user->username === 'stoop' && $d->status === 'approved' && str_contains($d->body, 'Edited by a person') && str_contains($d->body, 'oanc.dc.gov/taxonomy/term/19')));
        $this->assertDatabaseHas('audit_logs', ['action' => 'anc_summary.published', 'actor_id' => $staff->id]);

        // Ward 2 has more stations than the per-minute posting limit.
        Livewire::test(AncSummaries::class)->set('anc', '2C')->set('notes', 'Notes about downtown')->set('summary', 'Downtown news from the commission this month.')->call('publish')->assertHasNoErrors();
        $this->assertSame(count(Neighborhoods::stationsInWard(2)), Dispatch::where('body', 'like', 'ANC 2C%')->count());

        $this->actingAs(User::factory()->create());
        Livewire::test(AncSummaries::class)->assertForbidden();
    }
}
