<?php

namespace App\Services;

use App\Enums\PreventiveMaintenanceStatus;
use App\Models\PreventiveMaintenanceOccurrence;
use App\Notifications\PreventiveMaintenanceNotice;

class PreventiveMaintenanceNoticeService
{
    public function send(): void
    {
        $now = now(config('app.timezone'));
        PreventiveMaintenanceOccurrence::with(['asset', 'assignees.user'])
            ->whereIn('status', [PreventiveMaintenanceStatus::Available->value, PreventiveMaintenanceStatus::Overdue->value, PreventiveMaintenanceStatus::InProgress->value])
            ->where(fn ($query) => $query->whereNull('due_notice_sent_at')->orWhereNull('overdue_notice_sent_at'))
            ->chunkById(100, function ($occurrences) use ($now): void {
                foreach ($occurrences as $occurrence) {
                    $overdue = $occurrence->due_at->lt($now);
                    $column = $overdue ? 'overdue_notice_sent_at' : 'due_notice_sent_at';
                    if ($occurrence->{$column}) {
                        continue;
                    }
                    $event = $overdue ? 'PM_OVERDUE' : 'PM_DUE';
                    $occurrence->assignees->each(fn ($personnel) => $personnel->user?->notify(new PreventiveMaintenanceNotice($occurrence, $event)));
                    $occurrence->update([$column => $now]);
                }
            });
    }
}
