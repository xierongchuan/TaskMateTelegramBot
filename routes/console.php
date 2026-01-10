<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Command to test workers manually
Artisan::command('workers:test {type=all}', function ($type) {
    $this->info("Testing worker: {$type}");
    $this->info("Current time: " . now()->format('Y-m-d H:i:s T'));
    $this->info('Use "php artisan queue:work --queue=notifications" to process the jobs');
})->purpose('Test notification workers manually');

// Scheduled tasks for task notifications according to 4-scenario system

// Сценарий 1: Уведомление когда задача стала доступна (appear_date)
// Send scheduled tasks based on appear_date - runs every 5 minutes at exactly 0 seconds
Schedule::job(new \App\Jobs\SendScheduledTasksJob())->cron('*/5 * * * *');

// Сценарий 2: Напоминание за 30 минут до дедлайна
// Check for upcoming deadlines (30 minutes before) - runs every 5 minutes at exactly 0 seconds
Schedule::job(new \App\Jobs\CheckUpcomingDeadlinesJob())->cron('*/5 * * * *');

// Сценарий 3: Уведомление когда дедлайн истёк (просрочка в момент дедлайна)
// Check for overdue tasks at deadline time - runs every 5 minutes at exactly 0 seconds
Schedule::job(new \App\Jobs\CheckOverdueTasksJob())->cron('*/5 * * * *');

// Сценарий 4: Уведомление когда просрочено на час
// Check for tasks overdue by 1 hour - runs every 10 minutes at exactly 0 seconds
Schedule::job(new \App\Jobs\CheckHourlyOverdueJob())->cron('*/10 * * * *');

// Process recurring tasks - runs hourly
Schedule::job(new \App\Jobs\ProcessRecurringTasksJob())->hourly();

// Additional tasks for management
// Check for tasks without response
Schedule::job(new \App\Jobs\CheckUnrespondedTasksJob())->everyThirtyMinutes();

// Check for tasks to archive - runs every 10 minutes (command handles settings and time logic)
Schedule::command('tasks:archive-completed --type=all')->everyTenMinutes();

// Send daily summary to managers - runs daily at end of business day (20:00)
Schedule::job(new \App\Jobs\SendDailySummaryJob())->dailyAt('20:00');

// Send weekly reports - runs every Monday at 9:00 AM
Schedule::job(new \App\Jobs\SendWeeklyReportJob())->weekly()->mondays()->at('09:00');
