<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\AutoDealership;
use App\Models\Setting;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Queue;
use App\Jobs\ArchiveOldTasksJob;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager1;
    private AutoDealership $dealership1;
    private Setting $setting1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealership1 = AutoDealership::factory()->create();
        $this->owner = User::factory()->create(['role' => Role::OWNER->value]);
        $this->manager1 = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership1->id,
        ]);

        $this->setting1 = Setting::factory()->create(['dealership_id' => $this->dealership1->id]);
    }

    // Test Authorization
    public function test_manager_can_update_setting_in_their_dealership()
    {
        Sanctum::actingAs($this->manager1);
        $this->putJson('/api/v1/settings/' . $this->setting1->id, ['value' => 'new_value'])
             ->assertStatus(200);
        $this->assertDatabaseHas('settings', ['id' => $this->setting1->id, 'value' => 'new_value']);
    }

    public function test_manager_cannot_update_global_setting()
    {
        Sanctum::actingAs($this->manager1);
        $globalSetting = Setting::factory()->create(['dealership_id' => null]);
        $this->putJson('/api/v1/settings/' . $globalSetting->id, ['value' => 'new_value'])
             ->assertStatus(403);
    }

    public function test_owner_can_update_any_setting()
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/settings/' . $this->setting1->id, ['value' => 'owner_value'])
             ->assertStatus(200);
        $this->assertDatabaseHas('settings', ['id' => $this->setting1->id, 'value' => 'owner_value']);
    }

    // Test Archive Endpoint
    public function test_owner_can_trigger_archive_job()
    {
        Queue::fake();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/settings/archive-tasks', ['days' => 45])->assertStatus(200);
        Queue::assertPushed(ArchiveOldTasksJob::class, function ($job) {
            return $job->daysOverride === 45;
        });
    }

    public function test_manager_cannot_trigger_archive_job()
    {
        Queue::fake();
        Sanctum::actingAs($this->manager1);
        $this->postJson('/api/v1/settings/archive-tasks')->assertStatus(403);
        Queue::assertNotPushed(ArchiveOldTasksJob::class);
    }
}
