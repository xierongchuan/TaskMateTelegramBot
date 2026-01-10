<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\ImportantLink;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DealershipSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating Dealerships and Users...');

        $dealerships = [
            [
                'name' => 'Avto Salon Center',
                'address' => 'Tashkent, Amir Temur 1',
            ],
            [
                'name' => 'Avto Salon Sever',
                'address' => 'Tashkent, Yunusabad 19',
            ],
            [
                'name' => 'Auto Salon Lux',
                'address' => 'Tashkent, Chilanzar 5',
            ],
        ];

        foreach ($dealerships as $index => $data) {
            $dealership = AutoDealership::factory()->create($data);
            $this->command->info("Created dealership: {$dealership->name}");

            // Create Manager
            $managerLogin = 'manager' . ($index + 1);
            $manager = User::updateOrCreate(
                ['login' => $managerLogin],
                [
                    'full_name' => "Manager of {$dealership->name}",
                    'password' => Hash::make('password'),
                    'role' => Role::MANAGER,
                    'dealership_id' => $dealership->id,
                    'phone' => fake()->phoneNumber(),
                    'telegram_id' => fake()->unique()->randomNumber(9),
                ]
            );
            $this->command->info(" - Created Manager: {$manager->login} / password");

            // Create Employees
            for ($i = 1; $i <= 3; $i++) {
                $empLogin = 'emp' . ($index + 1) . '_' . $i;
                $employee = User::updateOrCreate(
                    ['login' => $empLogin],
                    [
                        'full_name' => "Employee {$i} of {$dealership->name}",
                        'password' => Hash::make('password'),
                        'role' => Role::EMPLOYEE,
                        'dealership_id' => $dealership->id,
                        'phone' => fake()->phoneNumber(),
                        'telegram_id' => fake()->unique()->randomNumber(9),
                    ]
                );
                $this->command->info(" - Created Employee: {$employee->login} / password");
            }

            // Create Important Links
            ImportantLink::factory(5)->create([
                'dealership_id' => $dealership->id,
                'creator_id' => $manager->id,
            ]);
            $this->command->info(" - Created 5 Important Links");
        }
    }
}
