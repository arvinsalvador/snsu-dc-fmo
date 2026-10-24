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
    public function activePendingRequest(InspectableAsset $asset): ?AssetActionRequest
    {
        $type = match ($asset->lifecycle_status?->value ?? (string) $asset->lifecycle_status) {
            'FOR_DISPOSAL_REVIEW' => 'DISPOSAL',
            'ACTIVE' => 'REPAIR',
            default => null,
        };

        return $type
            ? $asset->actionRequests()->where('status', 'PENDING')->where('type', $type)->latest('requested_at')->first()
            : null;
    }

    public function assertCurrentReview(AssetActionRequest $request): void
    {
        $current = $this->activePendingRequest($request->asset);
        if ($request->status !== 'PENDING' || ! $current || ! $current->is($request)) {
            throw ValidationException::withMessages([
                'decision' => 'This review has already been processed or superseded. Refresh the asset page to see the current review.',
            ]);
        }
    }

    public function request(InspectableAsset $asset, User $user, string $type, string $description, ?InspectionItem $inspection = null): AssetActionRequest
    {
        return DB::transaction(function () use ($asset, $user, $type, $description, $inspection): AssetActionRequest {
            /** @var InspectableAsset $lockedAsset */
            $lockedAsset = InspectableAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $lifecycle = $lockedAsset->lifecycle_status?->value ?? (string) $lockedAsset->lifecycle_status;
            $pending = $lockedAsset->actionRequests()->where('status', 'PENDING')->lockForUpdate()->latest('requested_at')->get();

            if ($type === 'REPAIR' && $lifecycle !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'type' => $lifecycle === 'FOR_DISPOSAL_REVIEW'
                        ? 'This asset is awaiting disposal review. Use the current disposal decision to send it back for repair.'
                        : 'A repair review can only be opened while the asset is active. Refresh the page to see its current lifecycle action.',
                ]);
            }
            if ($type === 'DISPOSAL' && ! in_array($lifecycle, ['ACTIVE', 'UNDER_REPAIR', 'FOR_DISPOSAL_REVIEW'], true)) {
                throw ValidationException::withMessages([
                    'type' => 'A disposal review cannot be opened from the asset’s current lifecycle. Refresh the page to see the available action.',
                ]);
            }

            if ($existing = $pending->firstWhere('type', $type)) {
                return $existing;
            }

            if ($type === 'DISPOSAL') {
                $pending->each(fn (AssetActionRequest $pendingRequest) => $pendingRequest->update([
                    'status' => 'SUPERSEDED',
                    'reviewed_by' => $user->id,
                    'reviewed_at' => now(),
                    'decision' => 'SUPERSEDED',
                    'decision_reason' => 'Replaced by the active disposal review.',
                ]));
            } elseif ($pending->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'type' => 'This asset already has an active review. Complete or return that review before opening another one.',
                ]);
            }

            $request = $lockedAsset->actionRequests()->create([
                'inspection_item_id' => $inspection?->id, 'type' => $type, 'status' => 'PENDING',
                'requested_by' => $user->id, 'requested_at' => now(), 'problem_description' => $description,
                'recommended_action' => $type === 'REPAIR' ? 'Assess and repair the asset.' : 'Review the asset for disposal.',
            ]);
            if ($type === 'DISPOSAL') {
                $this->transition($lockedAsset, $user, 'DISPOSAL_REQUESTED', 'FOR_DISPOSAL_REVIEW', null, $description, $request);
            } else {
                $this->event($lockedAsset, $user, 'REPAIR_REQUESTED', $description, $request);
            }
            $this->notifyReviewers($request, $type === 'REPAIR' ? 'ASSET_REPAIR_REQUESTED' : 'ASSET_DISPOSAL_REQUESTED');

            return $request;
        });
    }

    public function transition(InspectableAsset $asset, User $actor, string $event, ?string $lifecycle = null, ?string $condition = null, ?string $remarks = null, ?AssetActionRequest $request = null): void
    {
        $fromLifecycle = $asset->lifecycle_status?->value ?? (string) $asset->lifecycle_status;
        $fromCondition = $asset->condition?->value ?? (string) $asset->condition;
        if ($lifecycle && $lifecycle !== $fromLifecycle && ! $this->transitionAllowed($fromLifecycle, $lifecycle)) {
            throw ValidationException::withMessages([
                'lifecycle_status' => 'This asset’s lifecycle has changed. Refresh the page and use the review or action that is currently available.',
            ]);
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
