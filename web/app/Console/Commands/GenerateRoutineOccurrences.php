<?php

namespace App\Console\Commands;

use App\Enums\RoutineOccurrenceStatus;
use App\Enums\RoutineTaskType;
use App\Models\RoutineTaskOccurrence;
use App\Models\User;
use App\Notifications\InspectionAssignmentNotice;
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
        RoutineTaskOccurrence::with(['template', 'assignees.user', 'inspectionRun.items.activeAssignees.user'])
            ->whereIn('status', [RoutineOccurrenceStatus::Due->value, RoutineOccurrenceStatus::Overdue->value])
            ->whereDate('expected_date', '<=', now(config('app.timezone'))->toDateString())->chunkById(100, function ($occurrences): void {
                foreach ($occurrences as $occurrence) {
                    $overdue = $occurrence->status === RoutineOccurrenceStatus::Overdue;
                    $event = $overdue ? 'ROUTINE_OVERDUE' : 'ROUTINE_DUE';
                    if ($occurrence->template->task_type === RoutineTaskType::Inspection && $occurrence->inspectionRun) {
                        $inspectionEvent = $overdue ? 'INSPECTION_OVERDUE' : 'INSPECTION_DUE';
                        $assignments = $occurrence->inspectionRun->items->where('status', '!=', 'INSPECTED')
                            ->flatMap(fn ($item) => $item->activeAssignees->map(fn ($personnel) => ['personnel' => $personnel, 'item_id' => $item->id]))
                            ->groupBy(fn ($row) => $row['personnel']->id);
                        foreach ($assignments as $rows) {
                            $personnel = $rows->first()['personnel'];
                            $user = $personnel->user;
                            if (! $user || ! $personnel->isAssignable()) {
                                continue;
                            }
                            $exists = $user->notifications()->where('data->event_key', $inspectionEvent)->where('data->inspection_run_id', $occurrence->inspectionRun->id)->exists();
                            if (! $exists) {
                                $user->notify(new InspectionAssignmentNotice($occurrence->inspectionRun, $inspectionEvent, $rows->pluck('item_id')->unique()->count()));
                            }
                        }
                    } else {
                        foreach ($occurrence->assignees as $personnel) {
                            $user = $personnel->user;
                            if (! $user || ! $personnel->isAssignable()) {
                                continue;
                            }
                            $exists = $user->notifications()->where('data->event_key', $event)->where('data->routine_occurrence_id', $occurrence->id)->exists();
                            if (! $exists) {
                                $user->notify(new RoutineTaskNotice($occurrence, $event));
                            }
                        }
                    }
                    if ($overdue) {
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
