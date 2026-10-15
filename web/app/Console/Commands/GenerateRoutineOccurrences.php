<?php

namespace App\Console\Commands;

use App\Enums\RoutineOccurrenceStatus;
use App\Models\RoutineTaskOccurrence;
use App\Models\User;
use App\Notifications\RoutineTaskNotice;
use App\Services\RoutineOccurrenceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateRoutineOccurrences extends Command
{
    protected $signature = 'routine:generate {--from=} {--to=}';

    protected $description = 'Generate routine task occurrences, backfill missed periods, and send due notices';

    public function handle(RoutineOccurrenceService $service): int
    {
        $tz = config('app.timezone', 'Asia/Manila');
        $from = CarbonImmutable::parse($this->option('from') ?: now($tz)->subDays(config('routine_operations.generation.backfill_days'))->toDateString(), $tz);
        $to = CarbonImmutable::parse($this->option('to') ?: now($tz)->addDays(config('routine_operations.generation.lookahead_days'))->toDateString(), $tz);
        $count = $service->generate($from, $to);
        $this->sendNotices();
        $this->info("Generated {$count} new routine task occurrence(s).");

        return self::SUCCESS;
    }

    private function sendNotices(): void
    {
        RoutineTaskOccurrence::with(['template.activeAssignees.user'])
            ->whereIn('status', [RoutineOccurrenceStatus::Due->value, RoutineOccurrenceStatus::Overdue->value])
            ->whereDate('expected_date', '<=', now(config('app.timezone'))->toDateString())->chunkById(100, function ($occurrences): void {
                foreach ($occurrences as $occurrence) {
                    $event = $occurrence->status === RoutineOccurrenceStatus::Overdue ? 'ROUTINE_OVERDUE' : 'ROUTINE_DUE';
                    foreach ($occurrence->template->activeAssignees as $personnel) {
                        $user = $personnel->user;
                        if (! $user || ! $personnel->isAssignable()) {
                            continue;
                        }
                        $exists = $user->notifications()->where('data->event_key', $event)->where('data->routine_occurrence_id', $occurrence->id)->exists();
                        if (! $exists) {
                            $user->notify(new RoutineTaskNotice($occurrence, $event));
                        }
                    }
                    if ($event === 'ROUTINE_OVERDUE') {
                        User::permission('routine_tasks.view_all')->get()->each(function (User $user) use ($occurrence, $event): void {
                            $exists = $user->notifications()->where('data->event_key', $event)->where('data->routine_occurrence_id', $occurrence->id)->exists();
                            if (! $exists) {
                                $user->notify(new RoutineTaskNotice($occurrence, $event));
                            }
                        });
                    }
                }
            });
    }
}
