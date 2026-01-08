<?php

declare(strict_types=1);

namespace App\Bot\Commands\Manager;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Models\User;
use App\Models\Shift;
use App\Traits\MaterialDesign3Trait;
use SergiX44\Nutgram\Nutgram;
use Carbon\Carbon;

/**
 * Command for managers to view shifts.
 * MD3: List presentation with status indicators.
 */
class ViewShiftsCommand extends BaseCommandHandler
{
    use MaterialDesign3Trait;

    protected string $command = 'viewshifts';
    protected ?string $description = 'Просмотр смен';

    protected function execute(Nutgram $bot, User $user): void
    {
        // Get user's dealerships
        $dealershipIds = [$user->dealership_id];

        // Get active shifts for today
        $todayShifts = Shift::whereIn('dealership_id', $dealershipIds)
            ->whereNull('actual_end')
            ->whereDate('actual_start', Carbon::today())
            ->with('user')
            ->get();

        // Get completed shifts for today
        $completedShifts = Shift::whereIn('dealership_id', $dealershipIds)
            ->whereNotNull('actual_end')
            ->whereDate('actual_start', Carbon::today())
            ->with('user')
            ->get();

        $lines = [];
        $lines[] = '📊 *Смены сегодня*';

        if ($todayShifts->isEmpty() && $completedShifts->isEmpty()) {
            $lines[] = '';
            $lines[] = 'Нет смен на сегодня';
        } else {
            if ($todayShifts->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '*Активные:*';
                foreach ($todayShifts as $shift) {
                    $startTime = $shift->actual_start->format('H:i');
                    $status = $shift->status === 'late' ? '🔴' : '🟢';
                    $lines[] = "{$status} {$shift->user->name} · {$startTime}";
                }
            }

            if ($completedShifts->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '*Завершённые:*';
                foreach ($completedShifts as $shift) {
                    $startTime = $shift->actual_start->format('H:i');
                    $endTime = $shift->actual_end?->format('H:i') ?? '—';
                    $lines[] = "✓ {$shift->user->name} · {$startTime}–{$endTime}";
                }
            }
        }

        $lines[] = '';
        $lines[] = '💡 Полный функционал в веб-админке';

        $bot->sendMessage(implode("\n", $lines), parse_mode: 'Markdown');
    }
}
