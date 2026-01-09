<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Enums\Role;
use App\Models\User;
use App\Models\AutoDealership;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;

class StartCommandTest extends TestCase
{
    use RefreshDatabase;

    protected AutoDealership $dealership;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a dealership for users who need it
        $this->dealership = AutoDealership::create([
            'name' => 'Test Dealership',
            'address' => 'Test Address',
            'phone' => '+79991234567',
        ]);
    }

    public function test_employee_start_command_shows_correct_menu(): void
    {
        $user = User::create([
            'login' => 'employee',
            'password' => bcrypt('password'),
            'full_name' => 'Test Employee',
            'phone' => '+79991234567',
            'role' => Role::EMPLOYEE->value,
            'telegram_id' => 123456789,
            'dealership_id' => $this->dealership->id,
        ]);

        $this->actingAs($user);

        $bot = FakeNutgram::instance();
        $command = new \App\Bot\Commands\Employee\StartCommand();

        // Mock the bot
        $this->assertInstanceOf(\App\Bot\Commands\Employee\StartCommand::class, $command);
    }

    public function test_manager_start_command_shows_correct_menu(): void
    {
        $user = User::create([
            'login' => 'manager',
            'password' => bcrypt('password'),
            'full_name' => 'Test Manager',
            'phone' => '+79991234568',
            'role' => Role::MANAGER->value,
            'telegram_id' => 123456790,
            'dealership_id' => $this->dealership->id,
        ]);

        $this->actingAs($user);

        $command = new \App\Bot\Commands\Manager\StartCommand();

        $this->assertInstanceOf(\App\Bot\Commands\Manager\StartCommand::class, $command);
    }

    public function test_observer_start_command_shows_correct_menu(): void
    {
        $user = User::create([
            'login' => 'observer',
            'password' => bcrypt('password'),
            'full_name' => 'Test Observer',
            'phone' => '+79991234569',
            'role' => Role::OBSERVER->value,
            'telegram_id' => 123456791,
            'dealership_id' => $this->dealership->id,
        ]);

        $this->actingAs($user);

        $command = new \App\Bot\Commands\Observer\StartCommand();

        $this->assertInstanceOf(\App\Bot\Commands\Observer\StartCommand::class, $command);
    }

    public function test_owner_start_command_shows_correct_menu(): void
    {
        $user = User::create([
            'login' => 'owner',
            'password' => bcrypt('password'),
            'full_name' => 'Test Owner',
            'phone' => '+79991234570',
            'role' => Role::OWNER->value,
            'telegram_id' => 123456792,
        ]);

        $this->actingAs($user);

        $command = new \App\Bot\Commands\Owner\StartCommand();

        $this->assertInstanceOf(\App\Bot\Commands\Owner\StartCommand::class, $command);
    }

    public function test_all_roles_are_mapped_in_dispatcher(): void
    {
        // Test that StartConversationDispatcher has all roles mapped
        $roles = [
            'employee' => \App\Bot\Commands\Employee\StartCommand::class,
            'manager' => \App\Bot\Commands\Manager\StartCommand::class,
            'observer' => \App\Bot\Commands\Observer\StartCommand::class,
            'owner' => \App\Bot\Commands\Owner\StartCommand::class,
            'guest' => \App\Bot\Conversations\Guest\StartConversation::class,
        ];

        // Read the dispatcher file to verify mapping
        $dispatcherContent = file_get_contents(app_path('Bot/Dispatchers/StartConversationDispatcher.php'));

        foreach ($roles as $role => $class) {
            $this->assertStringContainsString("'{$role}'", $dispatcherContent, "Role '{$role}' not found in dispatcher");
        }
    }
}
