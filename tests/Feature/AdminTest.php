<?php

namespace Tests\Feature;

use App\Livewire\Admin\Places;
use App\Livewire\Admin\Sponsorships;
use App\Livewire\Admin\Users;
use App\Livewire\Admin\UserShow;
use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    protected function staff(): User
    {
        return User::factory()->create(['is_staff' => true]);
    }

    public function test_admin_is_staff_only(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.users'))->assertForbidden();
        Livewire::actingAs(User::factory()->create())->test(Users::class)->assertForbidden();
        auth()->logout();
        $this->get(route('admin'))->assertRedirect(route('login'));
    }

    public function test_csv_import_creates_and_updates_places(): void
    {
        $csv = "name,category,neighborhood,stop,list_name,list_rank\n"
            ."Sample Diner,Restaurant,Shaw,E02,Cheap Eats,1\n"
            ."Sample Cafe,Coffee,Petworth,e05,Cheap Eats,2\n"
            .",Bar,Nowhere,,Cheap Eats,3\n"
            ."Bad Station Bar,Bar,Somewhere,ZZZ,Cheap Eats,4\n";

        Livewire::actingAs($this->staff())->test(Places::class)
            ->set('csv', UploadedFile::fake()->createWithContent('list.csv', $csv))->call('import');

        $this->assertSame(2, Place::count());
        $this->assertSame('E05', Place::firstWhere('name', 'Sample Cafe')->stop);

        Livewire::actingAs($this->staff())->test(Places::class)
            ->set('csv', UploadedFile::fake()->createWithContent('list.csv', "name,list_name,list_rank\nSample Diner,Cheap Eats,5\n"))->call('import');
        $this->assertSame(5, Place::firstWhere('name', 'Sample Diner')->list_rank);
        $this->assertSame(2, Place::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'places.imported']);
    }

    public function test_approving_a_claim_makes_the_person_the_owner(): void
    {
        $place = Place::create(['name' => 'Sample Bakery']);
        $owner = User::factory()->create();
        $claim = PlaceClaim::create(['place_id' => $place->id, 'user_id' => $owner->id, 'role' => 'Owner', 'evidence' => 'Call the shop']);

        Livewire::actingAs($this->staff())->test(Places::class)->call('decideClaim', $claim->id, true);

        $this->assertSame($owner->id, $place->fresh()->owner_id);
        $this->assertStringContainsString('You can now post as Sample Bakery', $owner->notifications()->first()->data['message']);
    }

    public function test_staff_manage_people_and_every_action_is_audited(): void
    {
        $staff = $this->staff();
        $person = User::factory()->unverified()->create();

        Livewire::actingAs($staff)->test(UserShow::class, ['user' => $person])
            ->call('verify')
            ->set('subscribedUntil', now()->addYear()->toDateString())->call('saveSubscription')
            ->set('note', 'Talked to them about the rules on the phone.')->call('addNote')
            ->set('penaltyType', '24')->call('penalize')
            ->call('toggleStaff');

        $person->refresh();
        $this->assertTrue($person->hasVerifiedEmail());
        $this->assertTrue($person->subscribed_until->isFuture());
        $this->assertTrue($person->is_staff);
        $this->assertNotNull($person->activeTimeout());
        $this->assertSame(1, DB::table('user_notes')->count());
        $this->assertSame(
            ['penalty.issued', 'user.made_staff', 'user.subscription_set', 'user.verified'],
            DB::table('audit_logs')->orderBy('action')->pluck('action')->all(),
        );

        Livewire::actingAs($staff)->test(UserShow::class, ['user' => $staff])->call('toggleStaff')->assertStatus(422);
    }

    public function test_sponsorships_need_a_place_to_show_and_valid_dates(): void
    {
        Livewire::actingAs($this->staff())->test(Sponsorships::class)
            ->call('edit')->set('form.sponsor', 'Sample Bank')->set('form.headline', 'Free checking')
            ->call('save')->assertHasErrors(['form.stop', 'form.line'])
            ->set('form.stop', 'E04')->set('form.ends_on', now()->subYear()->toDateString())
            ->call('save')->assertHasErrors('form.ends_on')
            ->set('form.ends_on', now()->addMonth()->toDateString())->call('save')->assertHasNoErrors();

        $this->get('/?stop=E04')->assertSee('Free checking');
    }
}
