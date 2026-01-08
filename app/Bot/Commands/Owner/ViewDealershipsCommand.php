<?php

declare(strict_types=1);

namespace App\Bot\Commands\Owner;

use App\Bot\Abstracts\BaseCommandHandler;
use App\Models\User;
use App\Models\AutoDealership;
use App\Traits\MaterialDesign3Trait;
use SergiX44\Nutgram\Nutgram;

/**
 * Command for owners to view dealerships.
 * MD3: Card list with location and stats.
 */
class ViewDealershipsCommand extends BaseCommandHandler
{
    use MaterialDesign3Trait;

    protected string $command = 'viewdealerships';
    protected ?string $description = 'Просмотр салонов';

    protected function execute(Nutgram $bot, User $user): void
    {
        // Get all dealerships
        $dealerships = AutoDealership::withCount('users')->get();

        $lines = [];
        $lines[] = '🏢 *Автосалоны*';

        if ($dealerships->isEmpty()) {
            $lines[] = '';
            $lines[] = 'Нет салонов в системе';
        } else {
            foreach ($dealerships as $dealership) {
                $lines[] = '';
                $lines[] = "*{$dealership->name}*";
                $lines[] = "📍 {$dealership->address}";
                $lines[] = "👥 {$dealership->users_count} " .
                    $this->pluralizeRu($dealership->users_count, 'сотрудник', 'сотрудника', 'сотрудников');
            }
        }

        $lines[] = '';
        $lines[] = '💡 Управление в веб-админке';

        $bot->sendMessage(implode("\n", $lines), parse_mode: 'Markdown');
    }
}
