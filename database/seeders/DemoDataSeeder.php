<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\ImportantLink;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskGenerator;
use App\Models\TaskGeneratorAssignment;
use App\Models\TaskResponse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating Demo Data...');

        // 1. Create Dealerships
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

            // 2. Create Manager
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

            // 3. Create Employees
            $employees = [];
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
                $employees[] = $employee;
                $this->command->info(" - Created Employee: {$employee->login} / password");
            }

            // 4. Create Important Links
            ImportantLink::factory(5)->create([
                'dealership_id' => $dealership->id,
                'creator_id' => $manager->id,
            ]);
            $this->command->info(" - Created 5 Important Links");

            // 5. Create Task Generators with full history
            $this->createTaskGeneratorsWithHistory($dealership, $manager, $employees);

            // 6. Create One-time Tasks
            $this->createOneTimeTasks($dealership, $manager, $employees);
        }

        $this->command->info('Demo Data Creation Completed!');
    }

    /**
     * Create task generators with realistic history of generated tasks.
     */
    private function createTaskGeneratorsWithHistory($dealership, $manager, array $employees): void
    {
        $generators = [
            [
                'title' => 'Ежедневная проверка автомобилей',
                'description' => 'Проверить состояние всех автомобилей на площадке',
                'recurrence' => 'daily',
                'recurrence_time' => '09:00',
                'deadline_time' => '12:00',
                'task_type' => 'group',
                'response_type' => 'complete',
                'priority' => 'high',
                'tags' => ['проверка', 'автомобили'],
                'history_days' => 365, // Generate history for a year
            ],
            [
                'title' => 'Еженедельный отчет по продажам',
                'description' => 'Подготовить отчет по продажам за неделю',
                'recurrence' => 'weekly',
                'recurrence_time' => '10:00',
                'deadline_time' => '18:00',
                'recurrence_day_of_week' => 5, // Friday
                'task_type' => 'individual',
                'response_type' => 'complete',
                'priority' => 'medium',
                'tags' => ['отчет', 'продажи'],
                'history_days' => 365,
            ],
            [
                'title' => 'Ежемесячная инвентаризация',
                'description' => 'Провести инвентаризацию склада запчастей',
                'recurrence' => 'monthly',
                'recurrence_time' => '09:00',
                'deadline_time' => '18:00',
                'recurrence_day_of_month' => -1, // Last day of month
                'task_type' => 'group',
                'response_type' => 'complete',
                'priority' => 'high',
                'tags' => ['инвентаризация', 'склад'],
                'history_days' => 365,
            ],
            [
                'title' => 'Утренняя уборка шоурума',
                'description' => 'Ежедневная уборка и подготовка шоурума к открытию',
                'recurrence' => 'daily',
                'recurrence_time' => '08:00',
                'deadline_time' => '09:30',
                'task_type' => 'individual',
                'response_type' => 'acknowledge',
                'priority' => 'medium',
                'tags' => ['уборка', 'шоурум'],
                'history_days' => 90,
            ],
            [
                'title' => 'Еженедельное совещание команды',
                'description' => 'Обсуждение планов и результатов работы',
                'recurrence' => 'weekly',
                'recurrence_time' => '14:00',
                'deadline_time' => '15:00',
                'recurrence_day_of_week' => 1, // Monday
                'task_type' => 'group',
                'response_type' => 'acknowledge',
                'priority' => 'low',
                'tags' => ['совещание', 'команда'],
                'history_days' => 180,
            ],
        ];

        $totalTasks = 0;

        foreach ($generators as $genData) {
            $startDate = Carbon::today()->subDays($genData['history_days']);

            $generator = TaskGenerator::create([
                'title' => $genData['title'],
                'description' => $genData['description'],
                'creator_id' => $manager->id,
                'dealership_id' => $dealership->id,
                'recurrence' => $genData['recurrence'],
                'recurrence_time' => $genData['recurrence_time'] . ':00',
                'deadline_time' => $genData['deadline_time'] . ':00',
                'recurrence_day_of_week' => $genData['recurrence_day_of_week'] ?? null,
                'recurrence_day_of_month' => $genData['recurrence_day_of_month'] ?? null,
                'start_date' => $startDate,
                'task_type' => $genData['task_type'],
                'response_type' => $genData['response_type'],
                'priority' => $genData['priority'],
                'tags' => $genData['tags'],
                'is_active' => true,
            ]);

            // Assign employees
            $assignedEmployees = [];
            if ($genData['task_type'] === 'group') {
                foreach ($employees as $emp) {
                    TaskGeneratorAssignment::create([
                        'generator_id' => $generator->id,
                        'user_id' => $emp->id,
                    ]);
                    $assignedEmployees[] = $emp;
                }
            } else {
                // Individual - assign to random employee
                $emp = $employees[array_rand($employees)];
                TaskGeneratorAssignment::create([
                    'generator_id' => $generator->id,
                    'user_id' => $emp->id,
                ]);
                $assignedEmployees[] = $emp;
            }

            // Generate historical tasks
            $tasksCreated = $this->generateHistoricalTasks(
                $generator,
                $assignedEmployees,
                $genData['history_days']
            );
            $totalTasks += $tasksCreated;
        }

        $this->command->info(" - Created " . count($generators) . " Task Generators with {$totalTasks} historical tasks");
    }

    /**
     * Generate historical tasks for a generator.
     */
    private function generateHistoricalTasks(TaskGenerator $generator, array $assignedEmployees, int $historyDays): int
    {
        $tasksCreated = 0;
        $today = Carbon::today('Asia/Yekaterinburg');
        $startDate = $today->copy()->subDays($historyDays);

        // Parse times
        $recurrenceTime = Carbon::createFromFormat('H:i:s', $generator->recurrence_time, 'Asia/Yekaterinburg');
        $deadlineTime = Carbon::createFromFormat('H:i:s', $generator->deadline_time, 'Asia/Yekaterinburg');

        $currentDate = $startDate->copy();

        while ($currentDate->lte($today)) {
            $shouldGenerate = false;

            switch ($generator->recurrence) {
                case 'daily':
                    $shouldGenerate = true;
                    break;
                case 'weekly':
                    $shouldGenerate = $currentDate->dayOfWeekIso === $generator->recurrence_day_of_week;
                    break;
                case 'monthly':
                    $targetDay = $generator->recurrence_day_of_month;
                    if ($targetDay > 0) {
                        $shouldGenerate = $currentDate->day === $targetDay;
                    } else {
                        // Negative days (e.g. -1 is last day of month)
                        $daysInMonth = $currentDate->daysInMonth;
                        $calculatedDay = $daysInMonth + $targetDay + 1;
                        $shouldGenerate = $currentDate->day === $calculatedDay;
                    }
                    break;
            }

            if ($shouldGenerate) {
                $appearDate = $currentDate->copy()->setTime($recurrenceTime->hour, $recurrenceTime->minute, 0);
                $deadline = $currentDate->copy()->setTime($deadlineTime->hour, $deadlineTime->minute, 0);

                // If deadline is before appear time, it's next day
                if ($deadline->lt($appearDate)) {
                    $deadline->addDay();
                }

                $task = Task::create([
                    'generator_id' => $generator->id,
                    'title' => $generator->title,
                    'description' => $generator->description,
                    'creator_id' => $generator->creator_id,
                    'dealership_id' => $generator->dealership_id,
                    'appear_date' => $appearDate,
                    'deadline' => $deadline,
                    'scheduled_date' => $currentDate->copy(),
                    'task_type' => $generator->task_type,
                    'response_type' => $generator->response_type,
                    'priority' => $generator->priority,
                    'tags' => $generator->tags,
                    'is_active' => true,
                ]);

                // Assign employees
                foreach ($assignedEmployees as $emp) {
                    TaskAssignment::create([
                        'task_id' => $task->id,
                        'user_id' => $emp->id,
                        'assigned_at' => $appearDate,
                    ]);
                }

                // Generate realistic responses for past tasks
                $this->generateTaskResponses($task, $assignedEmployees, $deadline);

                $tasksCreated++;
            }

            $currentDate->addDay();
        }

        // Update generator last_generated_at
        $generator->update(['last_generated_at' => $today]);

        return $tasksCreated;
    }

    /**
     * Generate realistic task responses.
     *
     * Statistics targets:
     * - ~75% completed on time
     * - ~10% completed late
     * - ~10% expired (not completed)
     * - ~5% still pending (recent tasks only)
     */
    private function generateTaskResponses(Task $task, array $assignedEmployees, Carbon $deadline): void
    {
        $now = Carbon::now('Asia/Yekaterinburg');
        $isPast = $deadline->lt($now);
        $isRecent = $deadline->diffInDays($now) < 3;

        // For future/today tasks, leave some pending
        if (!$isPast || ($isRecent && fake()->boolean(30))) {
            // Task is pending or recent - no responses yet
            return;
        }

        // Determine outcome: 75% on-time, 10% late, 15% expired
        $outcome = fake()->randomFloat(2, 0, 1);

        if ($outcome < 0.75) {
            // Completed on time
            $responseTime = fake()->dateTimeBetween(
                $task->appear_date,
                $deadline
            );
            $this->completeTask($task, $assignedEmployees, Carbon::parse($responseTime));
        } elseif ($outcome < 0.85) {
            // Completed late
            $hoursLate = fake()->numberBetween(1, 48);
            $responseTime = $deadline->copy()->addHours($hoursLate);
            if ($responseTime->gt($now)) {
                $responseTime = $now->copy()->subMinutes(fake()->numberBetween(1, 60));
            }
            $this->completeTask($task, $assignedEmployees, $responseTime);
        } else {
            // Expired - no completion, archive the task
            $task->update([
                'archived_at' => $deadline->copy()->addHours(24),
                'archive_reason' => 'expired',
                'is_active' => false,
            ]);
        }
    }

    /**
     * Complete a task with responses from assigned employees.
     */
    private function completeTask(Task $task, array $assignedEmployees, Carbon $responseTime): void
    {
        foreach ($assignedEmployees as $emp) {
            // For group tasks, add slight variation in response times
            $empResponseTime = $responseTime->copy();
            if (count($assignedEmployees) > 1) {
                $empResponseTime->addMinutes(fake()->numberBetween(0, 120));
            }

            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $emp->id,
                'status' => 'completed',
                'comment' => fake()->optional(0.3)->sentence(),
                'responded_at' => $empResponseTime,
            ]);
        }

        // Archive the task as completed
        $task->update([
            'archived_at' => $responseTime,
            'archive_reason' => 'completed',
            'is_active' => false,
        ]);
    }

    /**
     * Create one-time tasks for a dealership.
     */
    private function createOneTimeTasks($dealership, $manager, array $employees): void
    {
        // Individual Tasks for each employee - mix of active and completed
        foreach ($employees as $emp) {
            // Active tasks
            $activeTasks = Task::factory(2)->create([
                'dealership_id' => $dealership->id,
                'creator_id' => $manager->id,
                'task_type' => 'individual',
                'title' => 'Активная задача для ' . $emp->full_name,
                'recurrence' => null,
                'appear_date' => Carbon::now()->subHours(rand(1, 24)),
                'deadline' => Carbon::now()->addDays(rand(1, 7)),
            ]);

            foreach ($activeTasks as $task) {
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $emp->id,
                    'assigned_at' => $task->appear_date,
                ]);
            }

            // Completed tasks from the past
            $completedTasks = Task::factory(3)->create([
                'dealership_id' => $dealership->id,
                'creator_id' => $manager->id,
                'task_type' => 'individual',
                'title' => 'Выполненная задача для ' . $emp->full_name,
                'recurrence' => null,
                'appear_date' => Carbon::now()->subDays(rand(5, 30)),
                'deadline' => Carbon::now()->subDays(rand(1, 4)),
                'archived_at' => Carbon::now()->subDays(rand(1, 4)),
                'archive_reason' => 'completed',
                'is_active' => false,
            ]);

            foreach ($completedTasks as $task) {
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $emp->id,
                    'assigned_at' => $task->appear_date,
                ]);

                TaskResponse::create([
                    'task_id' => $task->id,
                    'user_id' => $emp->id,
                    'status' => 'completed',
                    'responded_at' => $task->archived_at,
                ]);
            }
        }

        // Group Tasks
        $groupTasks = Task::factory(2)->create([
            'dealership_id' => $dealership->id,
            'creator_id' => $manager->id,
            'task_type' => 'group',
            'title' => 'Групповое совещание',
            'recurrence' => null,
            'appear_date' => Carbon::now()->subHours(2),
            'deadline' => Carbon::now()->addDays(1),
        ]);

        foreach ($groupTasks as $task) {
            foreach ($employees as $emp) {
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $emp->id,
                    'assigned_at' => $task->appear_date,
                ]);
            }
        }

        $taskCount = count($employees) * 5 + 2;
        $this->command->info(" - Created {$taskCount} One-time Tasks");
    }
}
