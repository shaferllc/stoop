<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\Dispatch;
use App\Models\Penalty;
use App\Models\User;
use App\Support\Maintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return Sanctum::actingAs(User::factory()->create(['is_staff' => true]), ['admin']);
    }

    public function test_only_staff_with_an_admin_token_get_in(): void
    {
        $this->getJson('/api/admin/attention')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(), ['admin']);
        $this->getJson('/api/admin/attention')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['is_staff' => true]), ['dispatch:write']);
        $this->getJson('/api/admin/attention')->assertForbidden();

        $this->admin();
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('options.penalties.7', ['value' => 'ban', 'label' => 'Ban']);
    }

    public function test_moderating_the_queue(): void
    {
        $held = Dispatch::factory()->create(['status' => 'held']);
        $other = Dispatch::factory()->create(['status' => 'held']);
        $this->admin();

        $this->getJson('/api/admin/attention')->assertJsonPath('items.0.key', 'queue')->assertJsonPath('items.0.count', 2)->assertJsonPath('total', 2);
        $this->getJson('/api/admin/queue')->assertJsonCount(2)->assertJsonMissingPath('0.user.email');

        $this->postJson("/api/admin/dispatches/{$held->id}/approve")->assertOk();
        $this->assertSame('approved', $held->fresh()->status);

        $this->postJson("/api/admin/dispatches/{$other->id}/remove", ['reason' => config('stoop.rule_reasons.0'), 'penalty' => '24'])->assertOk();
        $this->assertSame('removed', $other->fresh()->status);
        $this->assertSame('timeout', $other->user->penalties()->first()->type);
        $this->assertDatabaseHas('audit_logs', ['action' => 'dispatch.removed']);

        $this->postJson("/api/admin/dispatches/{$other->id}/remove", ['reason' => 'nope', 'penalty' => 'forever'])->assertJsonValidationErrors(['reason', 'penalty']);
    }

    public function test_appeals_still_need_a_second_moderator(): void
    {
        $moderator = $this->admin();
        $dispatch = Dispatch::factory()->create(['status' => 'removed', 'moderated_by' => $moderator->id]);
        $penalty = Penalty::create(['user_id' => $dispatch->user_id, 'subject_hash' => 'x', 'type' => 'warning', 'reason' => 'Spam', 'moderator_id' => $moderator->id, 'dispatch_id' => $dispatch->id]);
        $appeal = Appeal::create(['user_id' => $dispatch->user_id, 'penalty_id' => $penalty->id, 'dispatch_id' => $dispatch->id, 'body' => 'Please', 'status' => 'open']);

        $this->getJson('/api/admin/appeals')->assertJsonCount(0);
        $this->postJson("/api/admin/appeals/{$appeal->id}/decide", ['grant' => true])->assertForbidden();

        $this->admin();
        $this->postJson("/api/admin/appeals/{$appeal->id}/decide", ['grant' => true])->assertOk();
        $this->assertSame('granted', $appeal->fresh()->status);
    }

    public function test_people_search_and_penalties(): void
    {
        $person = User::factory()->create(['username' => 'sample_person']);
        $this->admin();

        $this->getJson('/api/admin/users?q=sample_')->assertJsonPath('data.0.username', 'sample_person');
        $this->postJson("/api/admin/users/{$person->id}/penalize", ['type' => '72', 'reason' => config('stoop.rule_reasons.0')])->assertOk()->assertJsonPath('type', 'timeout');
        $penalty = $person->penalties()->first();
        $this->getJson("/api/admin/users/{$person->id}")->assertJsonPath('penalties.0.id', $penalty->id);

        $this->postJson("/api/admin/penalties/{$penalty->id}/lift")->assertOk();
        $this->assertNotNull($penalty->fresh()->lifted_at);
    }

    public function test_maintenance_runs_only_safe_tasks_and_logs_them(): void
    {
        $this->admin();

        $this->getJson('/api/admin/health')->assertOk()->assertJsonStructure(['checks' => [['name', 'ok', 'fix']], 'tasks' => [['key', 'label']]]);
        $this->postJson('/api/admin/maintenance/migrate:fresh')->assertNotFound();
        $this->postJson('/api/admin/maintenance/db:wipe')->assertNotFound();

        $this->postJson('/api/admin/maintenance/clear')->assertOk()->assertJsonPath('message', 'Caches cleared.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'maintenance.clear']);
        $this->getJson('/api/admin/log')->assertOk()->assertJsonStructure(['log']);
        $this->getJson('/api/admin/audit')->assertJsonPath('0.action', 'maintenance.clear');
    }

    public function test_backups_copy_the_database(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'stoop').'.sqlite';
        touch($file);
        config(['database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $file, 'foreign_key_constraints' => true]]);
        \DB::connection('backup_test')->statement('create table t (x)');
        \DB::setDefaultConnection('backup_test');

        $path = Maintenance::backup();

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, File::size($path));
        File::delete([$path, $file]);
        \DB::setDefaultConnection('sqlite');
    }
}
