<?php

namespace Tests\Feature;

use App\Livewire\Composer;
use App\Livewire\Pages\LostFound;
use App\Models\Dispatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class LostAndFoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['stoop.model_moderation' => false, 'services.openai.key' => null]);
    }

    public function test_lost_posts_need_details_a_stop_and_no_contact_info(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $composer = Livewire::actingAs($user)->test(Composer::class)->set('type', 'lost')->set('body', 'Orange tabby, answers to Mango');

        $composer->call('file')->assertHasErrors(['lostKind', 'lostStatus']);
        $composer->set('lostKind', 'pet')->set('lostStatus', 'lost')->call('file')->assertHasErrors('stop');
        $composer->set('stop', 'E04')->set('body', 'Orange tabby, call 202-555-0134')->call('file')->assertHasErrors('body');
        $composer->set('body', 'Orange tabby, email me@example.com')->call('file')->assertHasErrors('body');

        $composer->set('body', 'Orange tabby, answers to Mango, last seen by the fountain')
            ->set('photos', [UploadedFile::fake()->image('mango.jpg')])->call('file')->assertHasNoErrors();

        $d = Dispatch::latest('id')->first();
        $this->assertSame('lost', $d->type);
        $this->assertSame(['lost_kind' => 'pet', 'lost_status' => 'lost'], $d->details);
        $this->assertSame(1, $d->images()->count());
    }

    public function test_board_filters_and_only_the_poster_marks_it_reunited(): void
    {
        [$poster, $neighbor] = User::factory()->count(2)->create();
        $pet = Dispatch::factory()->for($poster)->create(['type' => 'lost', 'stop' => 'E04', 'line' => 'green', 'body' => 'Lost dog Biscuit', 'details' => ['lost_kind' => 'pet', 'lost_status' => 'lost']]);
        Dispatch::factory()->create(['type' => 'lost', 'stop' => 'A03', 'line' => 'red', 'body' => 'Found keys on a bench', 'details' => ['lost_kind' => 'item', 'lost_status' => 'found']]);

        Livewire::test(LostFound::class)->assertSee('Lost dog Biscuit')->assertSee('Found keys on a bench')->assertSee('Lost pet');
        Livewire::test(LostFound::class, ['stop' => 'E04'])->assertSee('Lost dog Biscuit')->assertDontSee('Found keys');
        Livewire::test(LostFound::class, ['line' => 'red'])->assertDontSee('Lost dog Biscuit')->assertSee('Found keys');
        Livewire::test(LostFound::class, ['kind' => 'item'])->assertDontSee('Lost dog Biscuit');

        Livewire::actingAs($neighbor)->test(LostFound::class)->call('markReunited', $pet->id)->assertForbidden();
        Livewire::actingAs($poster)->test(LostFound::class)->assertSee('Mark reunited')->call('markReunited', $pet->id);

        $this->assertNotNull($pet->fresh()->details['reunited_at']);
        Livewire::test(LostFound::class)->assertDontSee('Lost dog Biscuit');
        Livewire::test(LostFound::class, ['reunited' => true])->assertSee('Lost dog Biscuit')->assertSee('Reunited');
    }

    public function test_app_api_board_and_reunite(): void
    {
        $poster = User::factory()->create();
        $pet = Dispatch::factory()->for($poster)->create(['type' => 'lost', 'stop' => 'E04', 'details' => ['lost_kind' => 'pet', 'lost_status' => 'lost']]);

        Sanctum::actingAs($poster, ['app']);
        $this->getJson('/api/app/lost-and-found?stop=E04')->assertOk()->assertJsonPath('data.0.lost.label', 'Lost pet');
        $this->postJson('/api/app/dispatches', ['type' => 'lost', 'body' => 'Found a scooter', 'line' => 'red', 'stop' => 'A01', 'lost_kind' => 'item', 'lost_status' => 'found'])->assertCreated();
        $this->postJson("/api/app/dispatches/{$pet->id}/reunited")->assertOk();
        $this->getJson('/api/app/lost-and-found?reunited=1')->assertOk()->assertJsonPath('data.0.lost.reunited', true);
    }
}
