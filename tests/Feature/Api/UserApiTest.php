<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\AutoDealership;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager1;
    private User $manager2;
    private User $employee1;
    private AutoDealership $dealership1;
    private AutoDealership $dealership2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealership1 = AutoDealership::factory()->create();
        $this->dealership2 = AutoDealership::factory()->create();

        $this->owner = User::factory()->create(['role' => Role::OWNER->value]);
        $this->manager1 = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership1->id,
        ]);
        $this->manager2 = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership2->id,
        ]);
        $this->employee1 = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership1->id,
        ]);
    }

    // Test Authorization
    public function test_employee_cannot_access_user_api()
    {
        Sanctum::actingAs($this->employee1);
        $this->getJson('/api/v1/users')->assertStatus(403);
    }

    // Test Index Endpoint
    public function test_owner_can_see_all_users()
    {
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/users')->assertStatus(200)->assertJsonCount(4, 'data');
    }

    public function test_manager_can_only_see_users_from_their_dealership()
    {
        Sanctum::actingAs($this->manager1);
        $response = $this->getJson('/api/v1/users');
        $response->assertStatus(200)->assertJsonCount(2, 'data');
        $this->assertEquals($this->manager1->id, $response->json('data.0.id'));
        $this->assertEquals($this->employee1->id, $response->json('data.1.id'));
    }

    // Test Store Endpoint
    public function test_owner_can_create_any_user()
    {
        Sanctum::actingAs($this->owner);
        $userData = [
            'full_name' => 'New Manager',
            'login' => 'newmanager',
            'password' => 'Password123!',
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership2->id,
        ];
        $this->postJson('/api/v1/users', $userData)->assertStatus(201);
        $this->assertDatabaseHas('users', ['login' => 'newmanager']);
    }

    public function test_manager_can_create_employee_in_their_dealership()
    {
        Sanctum::actingAs($this->manager1);
        $userData = [
            'full_name' => 'New Employee',
            'login' => 'newemployee',
            'password' => 'Password123!',
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership1->id,
        ];
        $this->postJson('/api/v1/users', $userData)->assertStatus(201);
        $this->assertDatabaseHas('users', ['login' => 'newemployee']);
    }

    public function test_manager_cannot_create_user_in_another_dealership()
    {
        Sanctum::actingAs($this->manager1);
        $userData = [
            'full_name' => 'Invalid Employee',
            'login' => 'invalidemployee',
            'password' => 'Password123!',
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership2->id,
        ];
        $this->postJson('/api/v1/users', $userData)->assertStatus(403);
    }

    public function test_manager_cannot_create_another_manager()
    {
        Sanctum::actingAs($this->manager1);
        $userData = [
            'full_name' => 'New Manager',
            'login' => 'newmanager',
            'password' => 'Password123!',
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership1->id,
        ];
        $this->postJson('/api/v1/users', $userData)->assertStatus(403);
    }

    // Test Update Endpoint
    public function test_owner_can_update_any_user()
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/users/' . $this->manager1->id, ['full_name' => 'Updated Name'])
             ->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $this->manager1->id, 'full_name' => 'Updated Name']);
    }

    public function test_manager_can_update_employee_in_their_dealership()
    {
        Sanctum::actingAs($this->manager1);
        $this->putJson('/api/v1/users/' . $this->employee1->id, ['full_name' => 'Updated Name'])
             ->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $this->employee1->id, 'full_name' => 'Updated Name']);
    }

    public function test_manager_cannot_update_manager_in_another_dealership()
    {
        Sanctum::actingAs($this->manager1);
        $this->putJson('/api/v1/users/' . $this->manager2->id, ['full_name' => 'Updated Name'])
             ->assertStatus(403);
    }

    // Test Destroy Endpoint
    public function test_owner_can_deactivate_user()
    {
        Sanctum::actingAs($this->owner);
        $this->deleteJson('/api/v1/users/' . $this->manager1->id)->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $this->manager1->id, 'status' => 'inactive']);
    }

    public function test_manager_can_deactivate_employee_in_their_dealership()
    {
        Sanctum::actingAs($this->manager1);
        $this->deleteJson('/api/v1/users/' . $this->employee1->id)->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $this->employee1->id, 'status' => 'inactive']);
    }

    public function test_manager_cannot_deactivate_user_in_another_dealership()
    {
        Sanctum::actingAs($this->manager1);
        $this->deleteJson('/api/v1/users/' . $this->manager2->id)->assertStatus(403);
    }

    public function test_user_cannot_deactivate_themselves()
    {
        Sanctum::actingAs($this->owner);
        $this->deleteJson('/api/v1/users/' . $this->owner->id)->assertStatus(403);
    }
}
