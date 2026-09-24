<?php

namespace App\Services;

use App\Enums\WorkOrderStatus;
use App\Enums\WorkSessionOutcome;
use App\Enums\WorkUpdateType;
use App\Models\FmoPersonnel;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkSession;
use App\Models\WorkUpdate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WorkOrderExecutionService
{
    public function __construct(private readonly WorkOrderWorkflowService $workflow) {}

    private function assignment(WorkOrder $order, User $actor, string $permission)
    {
        abort_unless($actor->can($permission) && $actor->isApproved(), 403);
        $assignment = $order->activeAssignments()->whereHas('personnel', fn ($query) => $query->where('user_id', $actor->id))->with('personnel.user')->first();
        abort_unless($assignment && $assignment->personnel->isAssignable(), 403);

        return $assignment;
    }

    private function activeSession(WorkOrder $order, WorkSession $session, User $actor, string $permission): WorkSession
    {
        $assignment = $this->assignment($order, $actor, $permission);
        abort_unless($session->work_order_id === $order->id && $session->fmo_personnel_id === $assignment->fmo_personnel_id, 403);
        $active = $order->activeSessions()->whereKey($session->id)->lockForUpdate()->first();
        if (! $active) {
            throw new ConflictHttpException('This work session has already ended.');
        }

        return $active;
    }

    public function start(WorkOrder $order, User $actor): WorkSession
    {
        return DB::transaction(function () use ($order, $actor): WorkSession {
            $locked = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $assignment = $this->assignment($locked, $actor, 'work_orders.start_assigned');
            if (! in_array($locked->status, [WorkOrderStatus::ReadyForWork, WorkOrderStatus::InProgress, WorkOrderStatus::ForContinuation, WorkOrderStatus::Paused, WorkOrderStatus::WaitingForMaterials], true)) {
                throw new ConflictHttpException('This Work Order is not ready for execution.');
            }
            FmoPersonnel::whereKey($assignment->fmo_personnel_id)->lockForUpdate()->firstOrFail();
            $other = WorkSession::with('workOrder')->where('fmo_personnel_id', $assignment->fmo_personnel_id)->whereNull('ended_at')->first();
            if ($other) {
                throw ValidationException::withMessages(['session' => "You currently have an active work session for {$other->workOrder->work_order_number}. End it before starting another Work Order."]);
            }
            $session = $locked->sessions()->create(['work_order_assignment_id' => $assignment->id, 'fmo_personnel_id' => $assignment->fmo_personnel_id, 'started_by_user_id' => $actor->id, 'started_at' => now(), 'active_marker' => 1]);
            $session->updates()->create(['work_order_id' => $locked->id, 'fmo_personnel_id' => $assignment->fmo_personnel_id, 'created_by_user_id' => $actor->id, 'type' => WorkUpdateType::Start, 'description' => 'Started work session.', 'requester_summary' => 'Work started.', 'recorded_at' => now(), 'execution_cycle' => $locked->execution_cycle]);
            $this->workflow->recordOperational($locked, $actor, $locked->status === WorkOrderStatus::InProgress ? 'TEAM_WORK_STARTED' : 'WORK_STARTED', 'Started work session '.$session->id, 'Work is in progress.');

            return $session;
        });
    }

    /** @param array<string, mixed> $data @param array<int, UploadedFile> $files */
    public function update(WorkOrder $order, WorkSession $session, User $actor, array $data, array $files = []): WorkUpdate
    {
        return DB::transaction(function () use ($order, $session, $actor, $data, $files): WorkUpdate {
            $locked = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $active = $this->activeSession($locked, $session, $actor, 'work_orders.update_assigned');
            if ($locked->status !== WorkOrderStatus::InProgress) {
                throw new ConflictHttpException('This Work Order is not accepting execution updates.');
            }
            $type = WorkUpdateType::from($data['type']);
            if (! in_array($type, [WorkUpdateType::Before, WorkUpdateType::Progress, WorkUpdateType::Issue, WorkUpdateType::Completion, WorkUpdateType::Other], true)) {
                throw ValidationException::withMessages(['type' => 'This update type is reserved for session actions.']);
            }
            $update = $active->updates()->create(['work_order_id' => $locked->id, 'fmo_personnel_id' => $active->fmo_personnel_id, 'created_by_user_id' => $actor->id, 'type' => $type, 'description' => $data['description'], 'requester_summary' => $data['requester_summary'] ?? null, 'recorded_at' => now(), 'execution_cycle' => $locked->execution_cycle]);
            $this->evidence($locked, $active, $update, $actor, $files, $type->value);
            $this->workflow->recordOperational($locked, $actor, 'WORK_UPDATE', $type->value.': '.$data['description'], $data['requester_summary'] ?? null);

            return $update->load('attachments');
        });
    }

    /** @param array<int, UploadedFile> $files */
    private function evidence(WorkOrder $order, WorkSession $session, WorkUpdate $update, User $actor, array $files, string $type): void
    {
        foreach ($files as $file) {
            $path = $file->store('work-orders/'.$order->id.'/execution/'.$update->id, 'local');
            $update->attachments()->create(['work_order_id' => $order->id, 'work_session_id' => $session->id, 'uploaded_by' => $actor->id, 'purpose' => 'EXECUTION', 'evidence_type' => $type, 'requester_visible' => false, 'original_filename' => $file->getClientOriginalName(), 'stored_path' => $path, 'mime_type' => $file->getMimeType(), 'file_size' => $file->getSize()]);
        }
    }

    /** @param array<string, mixed> $data @param array<int, UploadedFile> $files */
    public function end(WorkOrder $order, WorkSession $session, User $actor, array $data, array $files = []): WorkOrder
    {
        return DB::transaction(function () use ($order, $session, $actor, $data, $files): WorkOrder {
            $locked = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $active = $this->activeSession($locked, $session, $actor, 'work_orders.end_session');
            if ($locked->status !== WorkOrderStatus::InProgress) {
                throw new ConflictHttpException('This Work Order is not in progress.');
            }
            $outcome = WorkSessionOutcome::from($data['outcome']);
            if ($outcome === WorkSessionOutcome::Continuation && blank($data['remaining_work'] ?? null)) {
                throw ValidationException::withMessages(['remaining_work' => 'Describe the work remaining.']);
            }
            if ($outcome === WorkSessionOutcome::Continuation && config('work_orders.execution.require_continuation_evidence') && ! count($files) && ! $active->updates()->whereIn('type', [WorkUpdateType::Progress->value, WorkUpdateType::Issue->value])->whereHas('attachments')->exists()) {
                throw ValidationException::withMessages(['evidence' => 'Current-state evidence is required for continuation.']);
            }
            if ($outcome === WorkSessionOutcome::WaitingForMaterials && blank($data['material_description'] ?? null)) {
                throw ValidationException::withMessages(['material_description' => 'Describe the required materials or resources.']);
            }
            if ($outcome === WorkSessionOutcome::NeedsFurtherInvestigation && ! count($files) && ! $active->updates()->where('type', WorkUpdateType::Issue->value)->whereHas('attachments')->exists()) {
                throw ValidationException::withMessages(['evidence' => 'Provide evidence of the issue requiring investigation.']);
            }
            if ($outcome === WorkSessionOutcome::WorkOrderCompletionSubmitted && ! $actor->can('work_orders.submit_completion')) {
                abort(403);
            }
            $type = match ($outcome) {
                WorkSessionOutcome::Continuation => WorkUpdateType::Continuation,
                WorkSessionOutcome::Paused => WorkUpdateType::Pause,
                WorkSessionOutcome::WaitingForMaterials => WorkUpdateType::WaitingForMaterials,
                WorkSessionOutcome::NeedsFurtherInvestigation => WorkUpdateType::Investigation,
                WorkSessionOutcome::ContributionComplete, WorkSessionOutcome::WorkOrderCompletionSubmitted => WorkUpdateType::Completion,
            };
            $update = $active->updates()->create(['work_order_id' => $locked->id, 'fmo_personnel_id' => $active->fmo_personnel_id, 'created_by_user_id' => $actor->id, 'type' => $type, 'description' => $data['summary'], 'requester_summary' => $data['requester_summary'] ?? null, 'material_description' => $data['material_description'] ?? null, 'remaining_work' => $data['remaining_work'] ?? null, 'recorded_at' => now(), 'execution_cycle' => $locked->execution_cycle]);
            $this->evidence($locked, $active, $update, $actor, $files, $type->value);
            $active->update(['ended_at' => now(), 'end_outcome' => $outcome, 'session_summary' => $data['summary'], 'active_marker' => null]);
            $this->workflow->recordOperational($locked, $actor, 'SESSION_ENDED', $outcome->value.': '.$data['summary'], $data['requester_summary'] ?? null);
            if ($outcome === WorkSessionOutcome::WorkOrderCompletionSubmitted) {
                $this->submitLocked($locked, $actor, $data);
            } elseif (! $locked->activeSessions()->exists()) {
                $action = match ($outcome) {
                    WorkSessionOutcome::Continuation, WorkSessionOutcome::ContributionComplete => 'WORK_CONTINUATION',
                    WorkSessionOutcome::Paused => 'WORK_PAUSED',
                    WorkSessionOutcome::WaitingForMaterials => 'WORK_WAITING_MATERIALS',
                    WorkSessionOutcome::NeedsFurtherInvestigation => 'WORK_NEEDS_INVESTIGATION',
                    default => throw new \LogicException('Unexpected session outcome.'),
                };
                $this->workflow->recordOperational($locked, $actor, $action, $data['summary'], $data['requester_summary'] ?? null);
            }

            return $locked;
        });
    }

    /** @param array<string, mixed> $data */
    public function submit(WorkOrder $order, User $actor, array $data): WorkOrder
    {
        return DB::transaction(function () use ($order, $actor, $data): WorkOrder {
            $locked = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assignment($locked, $actor, 'work_orders.submit_completion');
            $this->submitLocked($locked, $actor, $data);

            return $locked;
        });
    }

    /** @param array<string, mixed> $data */
    private function submitLocked(WorkOrder $order, User $actor, array $data): void
    {
        if (! in_array($order->status, [WorkOrderStatus::InProgress, WorkOrderStatus::ForContinuation], true)) {
            throw new ConflictHttpException('This Work Order cannot be submitted for verification.');
        }
        if ($order->activeSessions()->exists()) {
            throw ValidationException::withMessages(['session' => 'All active team work sessions must be ended before submission.']);
        }
        if (blank($data['completion_summary'] ?? null) || blank($data['work_performed_summary'] ?? null)) {
            throw ValidationException::withMessages(['completion_summary' => 'A completion summary and work performed description are required.']);
        }
        $completion = $order->updates()->where('type', WorkUpdateType::Completion->value)->where('execution_cycle', $order->execution_cycle)->latest('recorded_at')->first();
        if (! $completion) {
            throw ValidationException::withMessages(['completion' => 'Record a completion update in a work session first.']);
        }
        if (config('work_orders.execution.require_before_evidence') && ! $order->updates()->where('type', WorkUpdateType::Before->value)->where('execution_cycle', $order->execution_cycle)->whereHas('attachments')->exists()) {
            throw ValidationException::withMessages(['evidence' => 'Before/initial evidence is required for this work category.']);
        }
        $hasEvidence = $order->updates()->where('type', WorkUpdateType::Completion->value)->where('execution_cycle', $order->execution_cycle)->whereHas('attachments', fn ($query) => $query->where('mime_type', 'like', 'image/%'))->exists();
        if (config('work_orders.execution.require_completion_photo') && ! $hasEvidence && mb_strlen(trim($data['non_photo_reason'] ?? '')) < 20) {
            throw ValidationException::withMessages(['non_photo_reason' => 'Attach completion evidence or provide a specific non-photographic justification of at least 20 characters.']);
        }
        $order->update(['completion_summary' => $data['completion_summary'], 'work_performed_summary' => $data['work_performed_summary'], 'non_photo_reason' => $data['non_photo_reason'] ?? null, 'completion_submitted_by' => $actor->id, 'completion_submitted_at' => now(), 'verified_by' => null, 'verified_at' => null, 'verification_note' => null]);
        $this->workflow->recordOperational($order, $actor, 'COMPLETION_SUBMITTED', $data['completion_summary'], $data['requester_summary'] ?? 'Work has been submitted for FMO verification.');
    }

    public function verify(WorkOrder $order, User $actor, ?string $note): WorkOrder
    {
        abort_unless($actor->can('work_orders.verify_completion'), 403);

        return DB::transaction(function () use ($order, $actor, $note): WorkOrder {
            $locked = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->activeSessions()->exists()) {
                throw new ConflictHttpException('Active sessions must be resolved before verification.');
            }
            $locked->update(['verified_by' => $actor->id, 'verified_at' => now(), 'verification_note' => $note]);
            $this->workflow->recordOperational($locked, $actor, 'COMPLETION_VERIFIED', $note, 'Work has been verified complete.');

            return $locked;
        });
    }

    public function returnForWork(WorkOrder $order, User $actor, string $reason): WorkOrder
    {
        abort_unless($actor->can('work_orders.return_for_work'), 403);

        return DB::transaction(function () use ($order, $actor, $reason): WorkOrder {
            $locked = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->workflow->recordOperational($locked, $actor, 'COMPLETION_RETURNED', $reason, 'Additional work is needed before completion.');
            $locked->increment('execution_cycle');

            return $locked;
        });
    }

    public function releaseInvestigation(WorkOrder $order, User $actor, string $note): WorkOrder
    {
        abort_unless($actor->can('work_orders.resolve_assessment'), 403);

        return DB::transaction(function () use ($order, $actor, $note): WorkOrder {
            $locked = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->activeSessions()->exists()) {
                throw ValidationException::withMessages(['session' => 'Resolve active sessions before releasing investigation.']);
            }
            $this->workflow->recordOperational($locked, $actor, 'INVESTIGATION_RELEASED', $note, 'Investigation reviewed; work may resume.');

            return $locked;
        });
    }

    public function forceEnd(WorkOrder $order, WorkSession $session, User $actor, string $reason): WorkOrder
    {
        abort_unless($actor->can('work_orders.manage_sessions'), 403);

        return DB::transaction(function () use ($order, $session, $actor, $reason): WorkOrder {
            $locked = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($session->work_order_id === $locked->id, 404);
            $active = $locked->activeSessions()->whereKey($session->id)->lockForUpdate()->first();
            if (! $active) {
                throw new ConflictHttpException('This session is already ended.');
            }
            $active->update(['ended_at' => now(), 'end_outcome' => WorkSessionOutcome::Paused, 'session_summary' => 'Administratively ended: '.$reason, 'active_marker' => null]);
            $this->workflow->recordOperational($locked, $actor, 'SESSION_FORCE_ENDED', 'Session '.$active->id.' ended by management: '.$reason);
            if ($locked->status === WorkOrderStatus::InProgress && ! $locked->activeSessions()->exists()) {
                $this->workflow->recordOperational($locked, $actor, 'WORK_PAUSED', $reason, 'Work is paused pending FMO review.');
            }

            return $locked;
        });
    }
}
