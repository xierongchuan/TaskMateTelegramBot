<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager1;
    private User $employee1;
    private AutoDealership $dealership1;
    private AutoDealership $dealership2;
    private Task $task1;

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
        $this->employee1 = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership1->id,
        ]);

        $this->task1 = Task::factory()->create(['dealership_id' => $this->dealership1->id]);
        TaskAssignment::factory()->create(['task_id' => $this->task1->id, 'user_id' => $this->employee1->id]);
    }

    // Test Authorization
    public function test_employee_cannot_access_task_api()
    {
        Sanctum::actingAs($this->employee1);
        $this->getJson('/api/v1/tasks')->assertStatus(403); // Assuming 403 Forbidden for employees
    }

    // Test Index Endpoint
    public function test_manager_can_only_see_tasks_from_their_dealership()
    {
        Sanctum::actingAs($this->manager1);
        Task::factory()->create(['dealership_id' => $this->dealership2->id]);
        $this->getJson('/api/v1/tasks')->assertStatus(200)->assertJsonCount(1, 'data');
    }

    // Test Store Endpoint
    public function test_manager_can_create_task_in_their_dealership()
    {
        Sanctum::actingAs($this->manager1);
        $taskData = [
            'title' => 'New Task',
            'response_type' => 'notification',
            'task_type' => 'individual',
            'dealership_id' => $this->dealership1->id,
            'assigned_users' => [$this->employee1->id],
        ];
        $this->postJson('/api/v1/tasks', $taskData)->assertStatus(201);
        $this->assertDatabaseHas('tasks', ['title' => 'New Task']);
    }

    public function test_manager_cannot_create_task_in_another_dealership()
    {
        Sanctum::actingAs($this->manager1);
        $taskData = [
            'title' => 'Invalid Task',
            'response_type' => 'notification',
            'task_type' => 'individual',
            'dealership_id' => $this->dealership2->id,
            'assigned_users' => [$this->employee1->id],
        ];
        $this->postJson('/api/v1/tasks', $taskData)->assertStatus(403);
    }

    // Test Update Endpoint with manual status update
    public function test_manager_can_manually_update_task_status()
    {
        Sanctum::actingAs($this->manager1);
        $updateData = [
            'user_status_update' => [
                'user_id' => $this->employee1->id,
                'status' => 'completed',
            ]
        ];
        $this->putJson('/api/v1/tasks/' . $this->task1->id, $updateData)->assertStatus(200);
        $this->assertDatabaseHas('task_responses', [
            'task_id' => $this->task1->id,
            'user_id' => $this->employee1->id,
            'status' => 'completed',
        ]);
    }

    // Test filtering
    public function test_tasks_can_be_filtered_by_status()
    {
        Sanctum::actingAs($this->owner);
        // Create a completed task
        $completedTask = Task::factory()->create(['dealership_id' => $this->dealership1->id]);
        \App\Models\TaskResponse::factory()->create([
            'task_id' => $completedTask->id,
            'user_id' => $this->employee1->id,
            'status' => 'completed'
        ]);

        $this->getJson('/api/v1/tasks?status=completed')
             ->assertStatus(200)
             ->assertJsonCount(1, 'data');
    }
}
