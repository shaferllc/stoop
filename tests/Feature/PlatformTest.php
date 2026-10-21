<?php

namespace Tests\Feature;

use App\Livewire\Admin\Dashboard;
use App\Livewire\Pages\Search;
use App\Models\Dispatch;
use App\Models\LinkPreview;
use App\Models\Penalty;
use App\Models\User;
use App\Support\Messaging;
use App\Support\Metro;
use App\Support\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_finds_word_forms_people_and_stations(): void
    {
        Dispatch::factory()->create(['body' => 'Best restaurant in Petworth, no contest']);
        Dispatch::factory()->create(['body' => 'Bike lane news on 14th']);
        User::factory()->create(['username' => 'mayaeats', 'name' => 'Maya R.']);

        Livewire::test(Search::class, ['q' => 'restaurants'])->assertSee('Best restaurant in Petworth')->assertDontSee('Bike lane');
        Livewire::test(Search::class, ['q' => 'maya', 'tab' => 'people'])->assertSee('@mayaeats');
        Livewire::test(Search::class, ['q' => 'petworth', 'tab' => 'stations'])->assertSee('E05');
        Livewire::test(Search::class, ['q' => '"; DROP TABLE dispatches; --'])->assertOk();
        $this->get(route('stops.show', 'e05'))->assertRedirect(route('home', ['stop' => 'E05']));
    }

    public function test_translation_is_offered_for_other_languages_and_cached(): void
    {
        config(['services.anthropic.key' => null]);
        $this->assertFalse(Translator::offer('¿Alguien sabe dónde venden pupusas buenas cerca de Columbia Heights?', 'en'));

        config(['services.anthropic.key' => 'test-key']);
        $this->assertTrue(Translator::offer('¿Alguien sabe dónde venden pupusas buenas cerca de Columbia Heights?', 'en'));
        $this->assertFalse(Translator::offer('Does anyone know where to get good pupusas near Columbia Heights?', 'en'));

        DB::table('translations')->insert(['content_hash' => hash('sha256', 'Hola vecinos'), 'language' => 'en', 'text' => 'Hello neighbors', 'created_at' => now()]);
        $this->assertSame('Hello neighbors', Translator::translate('Hola vecinos', 'en'));
    }

    public function test_metro_delays_show_when_a_key_is_set(): void
    {
        config(['services.wmata.key' => 'wmata-key']);
        Http::fake(['api.wmata.com/*' => Http::response(['Incidents' => [['LinesAffected' => 'RD;', 'Description' => 'Red Line: Single tracking at Dupont Circle.']]])]);

        $this->assertSame('red', Metro::incidents()[0]['line']);
        $this->get('/')->assertSee('Single tracking at Dupont Circle');
    }

    public function test_export_contains_your_data(): void
    {
        $user = User::factory()->create();
        Dispatch::factory()->for($user)->create(['body' => 'My dispatch for the export']);

        $json = $this->actingAs($user)->get(route('export'))->assertOk()->streamedContent();

        $this->assertStringContainsString('My dispatch for the export', $json);
        $this->assertSame($user->username, json_decode($json, true)['profile']['username']);
    }

    public function test_deleting_an_account_erases_it_but_keeps_tombstones_and_bans(): void
    {
        $user = User::factory()->create(['email' => 'leaving@example.com']);
        $lonely = Dispatch::factory()->for($user)->create();
        $discussed = Dispatch::factory()->for($user)->create(['body' => 'People replied to this']);
        $reply = Dispatch::factory()->create(['parent_id' => $discussed->id, 'body' => 'A reply that should survive']);
        $friend = User::factory()->create();
        $friend->following()->attach($user);
        Messaging::send($user, Messaging::start($user, [$friend])->fresh('participants'), 'A private message');
        Penalty::create(['user_id' => $user->id, 'subject_hash' => $user->subjectHash(), 'type' => 'timeout', 'reason' => 'Spam', 'ends_at' => now()->addWeek()]);

        $user->delete();

        $this->assertNull(Dispatch::withTrashed()->find($lonely->id));
        $tombstone = Dispatch::withTrashed()->find($discussed->id);
        $this->assertSame('', $tombstone->body);
        $this->assertNull($tombstone->user_id);
        $this->assertNotNull($reply->fresh());
        $this->assertSame(0, DB::table('messages')->count());
        $this->get(route('dispatches.show', $discussed->id))->assertOk()->assertSee('A reply that should survive');
        $this->assertNotNull(User::factory()->create(['email' => 'leaving@example.com'])->activeTimeout());
    }

    public function test_article_discussion_counts_pwa_and_operations(): void
    {
        $preview = LinkPreview::create(['url_hash' => hash('sha256', 'https://example.com/best-pizza'), 'url' => 'https://example.com/best-pizza', 'title' => 'Best Pizza']);
        Dispatch::factory()->count(2)->create(['link_preview_id' => $preview->id]);

        $this->getJson('/api/discussions?url='.urlencode('https://example.com/best-pizza'))->assertOk()->assertJsonPath('count', 2);
        $this->assertSame('Stoop', json_decode(file_get_contents(public_path('manifest.webmanifest')), true)['name']);
        $this->get('/')->assertSee('manifest.webmanifest', false);

        $this->actingAs(User::factory()->create())->get(route('admin'))->assertForbidden();
        Livewire::actingAs(User::factory()->create(['is_staff' => true]))->test(Dashboard::class)->assertSee('Dispatches, last 24 hours');
    }
}
