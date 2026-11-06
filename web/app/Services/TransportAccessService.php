<?php

namespace App\Services;

use App\Enums\TransportStatus;
use App\Models\FmoPersonnel;
use App\Models\TransportRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class TransportAccessService
{
    public function personnel(User $user): ?FmoPersonnel
    {
        return $user->personnelProfile()->where('personnel_status', 'ACTIVE')->whereNull('archived_at')->first();
    }

    /** @param Builder<TransportRequest> $query */
    public function scope(Builder $query, User $user): Builder
    {
        if ($user->can('transport.view_all')) {
            return $query;
        }
        $personnel = $this->personnel($user);

        return $query->where(function (Builder $inner) use ($user, $personnel): void {
            if ($user->can('transport.view_own')) {
                $inner->where('requester_id', $user->id);
            } else {
                $inner->whereRaw('1 = 0');
            }
            if ($personnel && $user->can('transport.update_assigned_trip')) {
                $inner->orWhere('driver_personnel_id', $personnel->id);
            }
        });
    }

    public function isRequester(TransportRequest $request, User $user): bool
    {
        return $request->requester_id === $user->id;
    }

    public function isAssignedDriver(TransportRequest $request, User $user): bool
    {
        $personnel = $this->personnel($user);

        return $personnel !== null && $request->driver_personnel_id === $personnel->id;
    }

    public function canView(TransportRequest $request, User $user): bool
    {
        return $user->can('transport.view_all')
            || ($user->can('transport.view_own') && $this->isRequester($request, $user))
            || ($user->can('transport.update_assigned_trip') && $this->isAssignedDriver($request, $user));
    }

    public function canApprove(TransportRequest $request, User $user): bool
    {
        return $request->status === TransportStatus::PendingApproval
            && ! $this->isRequester($request, $user)
            && ($user->can('transport.approve') || $user->can('transport.approve_delegated'));
    }

    public function canDispatch(TransportRequest $request, User $user): bool
    {
        return $user->can('transport.dispatch')
            && in_array($request->status, [TransportStatus::Approved, TransportStatus::Assigned], true);
    }

    public function canUpdateTrip(TransportRequest $request, User $user): bool
    {
        return $request->status === TransportStatus::Assigned
            && $user->can('transport.update_assigned_trip')
            && $this->isAssignedDriver($request, $user);
    }

    public function canCancel(TransportRequest $request, User $user): bool
    {
        return ! $request->status->isClosed()
            && (($this->isRequester($request, $user) && $user->can('transport.view_own')) || $user->can('transport.dispatch'));
    }

    public function canSeeApprovalEvidence(User $user): bool
    {
        return $user->can('transport.view_all');
    }

    public function canSeeTripDocuments(TransportRequest $request, User $user): bool
    {
        return $user->can('transport.view_all')
            || ($user->can('transport.update_assigned_trip') && $this->isAssignedDriver($request, $user));
    }

    public function canCreateCorrectiveWorkOrder(TransportRequest $request, User $user): bool
    {
        return $user->can('transport.dispatch') && $request->status === TransportStatus::Completed && $request->vehicle_issue_observed;
    }
}
