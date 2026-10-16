<?php

namespace App\Services;

use App\Models\AssetActionRequest;
use App\Models\InspectableAsset;
use App\Models\InspectionItem;
use App\Models\User;
use App\Notifications\AssetActionNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetLifecycleService
{
    public function request(InspectableAsset $asset, User $user, string $type, string $description, ?InspectionItem $inspection = null): AssetActionRequest
    {
        $existing = $asset->actionRequests()->where('type', $type)->where('status', 'PENDING')->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($asset, $user, $type, $description, $inspection): AssetActionRequest {
            $request = $asset->actionRequests()->create([
                'inspection_item_id' => $inspection?->id, 'type' => $type, 'status' => 'PENDING',
                'requested_by' => $user->id, 'requested_at' => now(), 'problem_description' => $description,
                'recommended_action' => $type === 'REPAIR' ? 'Assess and repair the asset.' : 'Review the asset for disposal.',
            ]);
            if ($type === 'DISPOSAL') {
                $this->transition($asset, $user, 'DISPOSAL_REQUESTED', 'FOR_DISPOSAL_REVIEW', null, $description, $request);
            } else {
                $this->event($asset, $user, 'REPAIR_REQUESTED', $description, $request);
            }
            $this->notifyReviewers($request, $type === 'REPAIR' ? 'ASSET_REPAIR_REQUESTED' : 'ASSET_DISPOSAL_REQUESTED');

            return $request;
        });
    }

    public function transition(InspectableAsset $asset, User $actor, string $event, ?string $lifecycle = null, ?string $condition = null, ?string $remarks = null, ?AssetActionRequest $request = null): void
    {
        $fromLifecycle = $asset->lifecycle_status?->value ?? (string) $asset->lifecycle_status;
        $fromCondition = $asset->condition?->value ?? (string) $asset->condition;
        if ($lifecycle && ! $this->transitionAllowed($fromLifecycle, $lifecycle)) {
            throw ValidationException::withMessages(['lifecycle_status' => "Lifecycle cannot change directly from {$fromLifecycle} to {$lifecycle}."]);
        }
        $updates = array_filter(['lifecycle_status' => $lifecycle, 'condition' => $condition], fn ($value) => $value !== null);
        if ($lifecycle) {
            $updates['status'] = match ($lifecycle) {
                'ACTIVE' => 'ACTIVE', 'RETIRED' => 'RETIRED', default => 'OUT_OF_SERVICE',
            };
        }
        $asset->update($updates);
        $asset->lifecycleEvents()->create([
            'asset_action_request_id' => $request?->id, 'actor_id' => $actor->id, 'event' => $event,
            'from_lifecycle' => $fromLifecycle, 'to_lifecycle' => $lifecycle ?: $fromLifecycle,
            'from_condition' => $fromCondition, 'to_condition' => $condition ?: $fromCondition, 'remarks' => $remarks,
        ]);
    }

    public function event(InspectableAsset $asset, User $actor, string $event, ?string $remarks = null, ?AssetActionRequest $request = null): void
    {
        $this->transition($asset, $actor, $event, null, null, $remarks, $request);
    }

    public function notifyReviewers(AssetActionRequest $request, string $event): void
    {
        $permission = $request->type === 'REPAIR' ? 'assets.review_repair' : 'assets.approve_disposal';
        User::permission($permission)->get()->each(fn (User $user) => $this->notifyOnce($user, $request, $event));
    }

    public function notifyRequester(AssetActionRequest $request, string $event): void
    {
        $this->notifyOnce($request->requester, $request, $event);
    }

    private function notifyOnce(User $user, AssetActionRequest $request, string $event): void
    {
        $exists = $user->notifications()->where('data->event_key', $event)->where('data->asset_action_request_id', $request->id)->exists();
        if (! $exists) {
            $user->notify(new AssetActionNotice($request, $event));
        }
    }

    private function transitionAllowed(string $from, string $to): bool
    {
        return in_array($to, match ($from) {
            'ACTIVE' => ['ACTIVE', 'UNDER_REPAIR', 'FOR_DISPOSAL_REVIEW', 'RETIRED'],
            'UNDER_REPAIR' => ['UNDER_REPAIR', 'ACTIVE', 'FOR_DISPOSAL_REVIEW'],
            'FOR_DISPOSAL_REVIEW' => ['ACTIVE', 'UNDER_REPAIR', 'APPROVED_FOR_DISPOSAL'],
            'APPROVED_FOR_DISPOSAL' => ['DISPOSED'],
            default => [],
        }, true);
    }
}
