<?php

namespace App\Services;

use App\Enums\RoutineOccurrenceStatus;
use App\Enums\RoutineTaskType;
use App\Models\RoutineTaskOccurrence;
use App\Models\User;
use App\Notifications\InspectionAssignmentNotice;
use App\Notifications\RoutineTaskNotice;

class RoutineNoticeService
{
    public function sendDueNotices(): void
    {
        RoutineTaskOccurrence::with(['template', 'assignees.user', 'inspectionRun.items.activeAssignees.user'])
            ->whereIn('status', [RoutineOccurrenceStatus::Due->value, RoutineOccurrenceStatus::Overdue->value])
            ->whereDate('expected_date', '<=', now(config('app.timezone'))->toDateString())
            ->chunkById(100, function ($occurrences): void {
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
                            $exists = $user->notifications()->where('data->event_key', $inspectionEvent)
                                ->where('data->inspection_run_id', $occurrence->inspectionRun->id)->exists();
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
                            $exists = $user->notifications()->where('data->event_key', $event)
                                ->where('data->routine_occurrence_id', $occurrence->id)->exists();
                            if (! $exists) {
                                $user->notify(new RoutineTaskNotice($occurrence, $event));
                            }
                        }
                    }
                    if ($overdue) {
                        User::permission('routine_tasks.view_all')->get()->each(function (User $user) use ($occurrence, $event): void {
                            $exists = $user->notifications()->where('data->event_key', $event)
                                ->where('data->routine_occurrence_id', $occurrence->id)->exists();
                            if (! $exists) {
                                $user->notify(new RoutineTaskNotice($occurrence, $event));
                            }
                        });
                    }
                }
            });
    }
}
