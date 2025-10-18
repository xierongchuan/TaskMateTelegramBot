<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AutoDealership;
use App\Models\Shift;
use App\Models\ShiftReplacement;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\TelegramNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Generate and send weekly reports to managers
 */
class GenerateWeeklyReport extends Command
{
    protected $signature = 'reports:weekly
                          {--dealership= : Generate report for specific dealership only}
                          {--format=both : Report format: telegram, pdf, or both}';

    protected $description = 'Generate weekly reports and send to managers (Telegram/PDF)';

    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly TelegramNotificationService $telegramService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Generating weekly reports...');

        $dealershipId = $this->option('dealership');
        $format = $this->option('format');

        // Get dealerships to process
        $dealerships = $dealershipId
            ? AutoDealership::where('id', $dealershipId)->get()
            : AutoDealership::all();

        if ($dealerships->isEmpty()) {
            $this->error('No dealerships found.');
            return self::FAILURE;
        }

        foreach ($dealerships as $dealership) {
            try {
                $this->info("Processing dealership: {$dealership->name}");
                $this->generateReportForDealership($dealership, $format);
            } catch (\Throwable $e) {
                $this->error("Error generating report for {$dealership->name}: " . $e->getMessage());
                Log::error("Error generating weekly report for dealership #{$dealership->id}", [
                    'exception' => $e,
                ]);
            }
        }

        $this->info('Weekly reports generated successfully.');
        return self::SUCCESS;
    }

    private function generateReportForDealership(AutoDealership $dealership, string $format): void
    {
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        // Collect report data
        $reportData = $this->collectReportData($dealership, $startOfWeek, $endOfWeek);

        // Generate and send based on format
        if (in_array($format, ['telegram', 'both'])) {
            $this->sendTelegramReport($dealership, $reportData);
        }

        if (in_array($format, ['pdf', 'both'])) {
            $this->generatePDFReport($dealership, $reportData, $startOfWeek, $endOfWeek);
        }
    }

    private function collectReportData(AutoDealership $dealership, Carbon $start, Carbon $end): array
    {
        // Shifts
        $shifts = Shift::where('dealership_id', $dealership->id)
            ->whereBetween('shift_start', [$start, $end])
            ->with('user', 'replacement.replacedUser', 'replacement.replacingUser')
            ->get();
        $lateShifts = $shifts->where('status', 'late');
        $replacements = $shifts->map(fn($s) => $s->replacement)->filter();

        // Tasks
        $taskResponses = TaskResponse::whereBetween('responded_at', [$start, $end])
            ->whereHas('task', fn($q) => $q->where('dealership_id', $dealership->id))
            ->with('task', 'user')
            ->get();

        $completedTasks = $taskResponses->where('status', 'completed');
        $postponedTasks = $taskResponses->where('status', 'postponed');

        $overdueTasks = Task::where('dealership_id', $dealership->id)
            ->whereBetween('deadline', [$start, $end])
            ->whereDoesntHave('responses', fn($q) => $q->where('status', 'completed'))
            ->get();

        // Top problems (most postponed tasks in the period)
        $topProblemIds = TaskResponse::where('status', 'postponed')
            ->whereBetween('responded_at', [$start, $end])
            ->select('task_id')
            ->groupBy('task_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(5)
            ->pluck('task_id');
        $topProblems = Task::find($topProblemIds);

        // Employee stats
        $employeeStats = [];
        $employees = User::where('dealership_id', $dealership->id)
            ->where('role', 'employee')
            ->withCount([
                'shifts' => fn($q) => $q->whereBetween('shift_start', [$start, $end]),
                'lateShifts' => fn($q) => $q->whereBetween('shift_start', [$start, $end]),
                'completedTasks' => fn($q) => $q->whereBetween('responded_at', [$start, $end]),
                'postponedTasks' => fn($q) => $q->whereBetween('responded_at', [$start, $end]),
            ])
            ->get();

        foreach($employees as $employee) {
            $employeeStats[] = [
                'name' => $employee->full_name,
                'shifts' => $employee->shifts_count,
                'late_shifts' => $employee->late_shifts_count,
                'completed_tasks' => $employee->completed_tasks_count,
                'postponed_tasks' => $employee->postponed_tasks_count,
            ];
        }

        return [
            'dealership' => $dealership,
            'period' => ['start' => $start, 'end' => $end],
            'summary' => [
                'total_shifts' => $shifts->count(),
                'late_shifts' => $lateShifts->count(),
                'replacements' => $replacements->count(),
                'completed_tasks' => $completedTasks->count(),
                'overdue_tasks' => $overdueTasks->count(),
                'postponed_tasks' => $postponedTasks->count(),
            ],
            'top_problems' => $topProblems,
            'employee_stats' => $employeeStats,
            'late_shifts_details' => $lateShifts,
            'replacements_details' => $replacements,
        ];
    }

    private function sendTelegramReport(AutoDealership $dealership, array $data): void
    {
        // Get managers for this dealership
        $managers = User::where('dealership_id', $dealership->id)
            ->whereIn('role', ['owner', 'manager'])
            ->get();

        if ($managers->isEmpty()) {
            $this->warn("No managers found for {$dealership->name}");
            return;
        }

        $message = $this->formatTelegramMessage($data);

        foreach ($managers as $manager) {
            if (!$manager->telegram_id) {
                continue;
            }

            try {
                $this->telegramService->sendMessage($manager->telegram_id, $message);
                $this->info("  - Sent Telegram report to {$manager->full_name}");
            } catch (\Throwable $e) {
                $this->error("  - Failed to send to {$manager->full_name}: " . $e->getMessage());
            }
        }
    }

    private function formatTelegramMessage(array $data): string
    {
        $period = $data['period']['start']->format('d.m.Y') . ' - ' . $data['period']['end']->format('d.m.Y');
        $summary = $data['summary'];

        $message = "📊 *Недельный отчёт*\n";
        $message .= "🏢 {$data['dealership']->name}\n";
        $message .= "📅 {$period}\n\n";

        $message .= "*Смены:*\n";
        $message .= "• Всего смен: {$summary['total_shifts']}\n";
        $message .= "• ⚠️ Опозданий: {$summary['late_shifts']}\n";
        $message .= "• 🔄 Замещений: {$summary['replacements']}\n\n";

        $message .= "*Задачи:*\n";
        $message .= "• ✅ Выполнено: {$summary['completed_tasks']}\n";
        $message .= "• 🚨 Просрочено: {$summary['overdue_tasks']}\n";
        $message .= "• Перенесено: {$summary['postponed_tasks']}\n\n";

        if (!empty($data['top_problems']) && $data['top_problems']->count() > 0) {
            $message .= "*Топ-проблемы недели:*\n";
            foreach ($data['top_problems'] as $idx => $task) {
                $message .= ($idx + 1) . ". {$task->title} (переносов: {$task->postpone_count})\n";
            }
            $message .= "\n";
        }

        $message .= "*По сотрудникам:*\n";
        foreach (array_slice($data['employee_stats'], 0, 10) as $stat) {
            $message .= "• {$stat['name']}: смен {$stat['shifts']}, выполнено {$stat['completed_tasks']}\n";
        }

        return $message;
    }

    private function generatePDFReport(AutoDealership $dealership, array $data, Carbon $start, Carbon $end): void
    {
        try {
            // Generate PDF using view
            $pdf = Pdf::loadView('reports.weekly', $data);

            // Save to storage
            $filename = sprintf(
                'reports/weekly_%s_%s.pdf',
                $dealership->id,
                $start->format('Y-m-d')
            );

            Storage::disk('public')->put($filename, $pdf->output());

            $this->info("  - PDF report saved: {$filename}");

            Log::info("Generated weekly PDF report", [
                'dealership_id' => $dealership->id,
                'filename' => $filename,
            ]);
        } catch (\Throwable $e) {
            $this->error("  - Failed to generate PDF: " . $e->getMessage());
            Log::error("Failed to generate PDF report", [
                'dealership_id' => $dealership->id,
                'exception' => $e,
            ]);
        }
    }
}
