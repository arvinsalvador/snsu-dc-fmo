<?php

namespace App\Services;

use App\Models\AssetActionAssignment;
use App\Models\AssetActionRequest;
use App\Models\FmoPersonnel;
use App\Models\InspectableAsset;
use App\Models\User;
use App\Notifications\AssetRepairAssignmentNotice;
use Illuminate\Support\Facades\DB;

class AssetMaintenanceAssignmentService
{
    /** @param array<int, string> $personnelIds */
    public function replaceTeam(AssetActionRequest $action, array $personnelIds, User $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($action, $personnelIds, $actor, $reason): void {
            $active = $action->assignments()->whereNull('unassigned_at')->get();
            $removed = $active->whereNotIn('fmo_personnel_id', $personnelIds);
            $added = collect($personnelIds)->diff($active->pluck('fmo_personnel_id'));
            $action->assignments()->whereNull('unassigned_at')->whereIn('fmo_personnel_id', $removed->pluck('fmo_personnel_id'))
                ->update(['unassigned_at' => now(), 'unassigned_by' => $actor->id, 'change_reason' => $reason, 'active_marker' => null]);
            foreach ($added as $personnelId) {
                AssetActionAssignment::create([
                    'asset_action_request_id' => $action->id, 'fmo_personnel_id' => $personnelId,
                    'assigned_by' => $actor->id, 'assigned_at' => now(), 'change_reason' => $reason, 'active_marker' => 1,
                ]);
            }
            $action->loadMissing('asset');
            FmoPersonnel::with('user')->whereIn('id', $added)->get()->each(fn (FmoPersonnel $personnel) => $personnel->user?->notify(new AssetRepairAssignmentNotice($action)));
            FmoPersonnel::with('user')->whereIn('id', $removed->pluck('fmo_personnel_id'))->get()->each(fn (FmoPersonnel $personnel) => $personnel->user?->notify(new AssetRepairAssignmentNotice($action, true)));
        });
    }

    public function activeRepairAction(InspectableAsset $asset): ?AssetActionRequest
    {
        return $asset->actionRequests()->whereIn('decision', ['APPROVE_REPAIR', 'SEND_FOR_REPAIR'])
            ->whereHas('assignments', fn ($query) => $query->whereNull('unassigned_at'))->latest('reviewed_at')->first();
    }

    public function assignedToRepair(InspectableAsset $asset, User $user): bool
    {
        $personnel = $user->personnelProfile;
        if (! $personnel?->isAssignable()) {
            return false;
        }

        return $asset->actionRequests()->whereIn('decision', ['APPROVE_REPAIR', 'SEND_FOR_REPAIR'])
            ->whereHas('assignments', fn ($query) => $query->where('fmo_personnel_id', $personnel->id)->whereNull('unassigned_at'))->exists();
    }
}
