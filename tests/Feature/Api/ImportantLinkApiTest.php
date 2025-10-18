<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\AutoDealership;
use App\Models\ImportantLink;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class ImportantLinkApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager1;
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

        ImportantLink::factory()->create(['dealership_id' => $this->dealership1->id]);
        ImportantLink::factory()->create(['dealership_id' => $this->dealership2->id]);
    }

    // Test Index Endpoint
    public function test_owner_can_see_all_links()
    {
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/important-links')->assertStatus(200)->assertJsonCount(2);
    }

    public function test_manager_can_only_see_links_from_their_dealership()
    {
        Sanctum::actingAs($this->manager1);
        $this->getJson('/api/v1/important-links')->assertStatus(200)->assertJsonCount(1);
    }

    // Test Store Endpoint
    public function test_owner_can_create_link_for_any_dealership()
    {
        Sanctum::actingAs($this->owner);
        $linkData = [
            'title' => 'Test Link',
            'url' => 'https://example.com',
            'dealership_id' => $this->dealership2->id,
        ];
        $this->postJson('/api/v1/important-links', $linkData)->assertStatus(201);
        $this->assertDatabaseHas('important_links', ['title' => 'Test Link']);
    }

    public function test_manager_can_create_link_for_their_dealership()
    {
        Sanctum::actingAs($this->manager1);
        $linkData = [
            'title' => 'Manager Link',
            'url' => 'https://example.com',
            'dealership_id' => $this->dealership1->id,
        ];
        $this->postJson('/api/v1/important-links', $linkData)->assertStatus(201);
        $this->assertDatabaseHas('important_links', ['title' => 'Manager Link']);
    }

    public function test_manager_cannot_create_link_for_another_dealership()
    {
        Sanctum::actingAs($this->manager1);
        $linkData = [
            'title' => 'Invalid Link',
            'url' => 'https://example.com',
            'dealership_id' => $this->dealership2->id,
        ];
        $this->postJson('/api/v1/important-links', $linkData)->assertStatus(403);
    }

    // Test Update Endpoint
    public function test_manager_can_update_link_in_their_dealership()
    {
        Sanctum::actingAs($this->manager1);
        $link = ImportantLink::where('dealership_id', $this->dealership1->id)->first();
        $this->putJson('/api/v1/important-links/' . $link->id, ['title' => 'Updated Title'])
             ->assertStatus(200);
        $this->assertDatabaseHas('important_links', ['id' => $link->id, 'title' => 'Updated Title']);
    }

    // Test Destroy Endpoint
    public function test_manager_can_delete_link_in_their_dealership()
    {
        Sanctum::actingAs($this->manager1);
        $link = ImportantLink::where('dealership_id', $this->dealership1->id)->first();
        $this->deleteJson('/api/v1/important-links/' . $link->id)->assertStatus(204);
        $this->assertDatabaseMissing('important_links', ['id' => $link->id]);
    }
}
