<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\AdminSeeder;
use Database\Seeders\DealershipSeeder;
use Database\Seeders\TaskSeeder;
use Illuminate\Console\Command;

class SeedDemoData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:seed-demo';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Interactively seed demo data with options for Admin, Dealerships, and Tasks';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Interactive Demo Data Seeder');
        $this->info('----------------------------');

        // 1. Admin User
        if ($this->confirm('Create Admin User?', true)) {
            $this->call('db:seed', [
                '--class' => AdminSeeder::class,
            ]);
        }

        // 2. Dealerships & Users
        if ($this->confirm('Create Dealerships, Managers, and Employees?', true)) {
            $this->call('db:seed', [
                '--class' => DealershipSeeder::class,
            ]);
        }

        // 3. Tasks
        if ($this->confirm('Generate Task History?', true)) {
            $days = $this->ask('How many days of history to generate?', '30');
            $days = (int)$days;

            if ($days < 0) {
                $this->error('Days cannot be negative.');
                return;
            }

            // Set static property on TaskSeeder
            TaskSeeder::$historyDays = $days;

            $this->call('db:seed', [
                '--class' => TaskSeeder::class,
            ]);
        }

        $this->info('----------------------------');
        $this->info('Demo Data Seeding Completed!');
    }
}
