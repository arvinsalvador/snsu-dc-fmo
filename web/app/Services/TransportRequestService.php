<?php

namespace App\Services;

use App\Enums\FuelLevel;
use App\Enums\TransportApprovalAuthority;
use App\Enums\TransportStatus;
use App\Models\FmoPersonnel;
use App\Models\InspectableAsset;
use App\Models\Skill;
use App\Models\TransportRequest;
use App\Models\User;
use App\Notifications\TransportNotice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Workflow rules for Transport Requests; shared by the web and API controllers. */
class TransportRequestService
{
    /** Trip fields a driver may edit before completion. */
    public const TRIP_FIELDS = [
        'actual_departed_at', 'actual_arrived_at', 'odometer_before', 'odometer_after', 'fuel_before', 'fuel_after',
        'trip_remarks', 'vehicle_issue_observed', 'vehicle_issue_remarks',
    ];

    public function __construct(
        private readonly StoredFileTransaction $files,
        private readonly OperationalEvidenceService $evidence,
        private readonly VehicleOdometerService $odometer,
    ) {}

    /** @return array<string, mixed> */
    public function requestRules(): array
    {
        return [
            'purpose' => ['required', 'string', 'max:500'],
            'origin' => ['required', 'string', 'max:255'],
            'destination' => ['required', 'string', 'max:255'],
            'travel_date' => ['required', 'date', 'after_or_equal:today'],
            'expected_departure_at' => ['nullable', 'date'],
            'expected_return_at' => ['nullable', 'date', 'after_or_equal:expected_departure_at'],
            'passenger_count' => ['required', 'integer', 'min:1', 'max:100'],
            'passenger_details' => ['nullable', 'string', 'max:3000'],
            'remarks' => ['nullable', 'string', 'max:3000'],
            ...$this->documentRules('documents'),
        ];
    }

    /** @return array<string, mixed> */
    public function approvalRules(): array
    {
        return [
            'approval_authority' => ['nullable', Rule::enum(TransportApprovalAuthority::class)],
            'approval_instructed_by' => ['nullable', 'string', 'max:150'],
            'approval_note' => ['nullable', 'string', 'max:2000'],
            ...$this->documentRules('approval_evidence'),
        ];
    }

    /** @return array<string, mixed> */
    public function assignmentRules(): array
    {
        return [
            'driver_personnel_id' => ['required', 'string'],
            'inspectable_asset_id' => ['required', 'string'],
        ];
    }

    /** @return array<string, mixed> */
    public function tripRules(Request $request): array
    {
        $max = (int) config('work_orders.attachments.max_count');

        return [
            'actual_departed_at' => ['nullable', 'date'], 'actual_arrived_at' => ['nullable', 'date'],
            'odometer_before' => ['nullable', 'integer', 'min:0', 'max:99999999'], 'odometer_after' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'fuel_before' => ['nullable', Rule::enum(FuelLevel::class)], 'fuel_after' => ['nullable', Rule::enum(FuelLevel::class)],
            'trip_remarks' => ['nullable', 'string', 'max:2000'],
            'vehicle_issue_observed' => ['nullable', 'boolean'],
            'vehicle_issue_remarks' => [Rule::requiredIf(fn () => $request->boolean('vehicle_issue_observed')), 'nullable', 'string', 'max:2000'],
            ...$this->documentRules('trip_documents'),
            'issue_photos' => ['nullable', 'array', 'max:'.$max],
            'issue_photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('work_orders.attachments.max_size_kb')],
        ];
    }

    /** @return array<string, mixed> */
    private function documentRules(string $field): array
    {
        return [
            $field => ['nullable', 'array', 'max:'.config('work_orders.attachments.max_count')],
            $field.'.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.config('work_orders.attachments.max_size_kb')],
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(User $requester, array $data): TransportRequest
    {
        $request = $this->files->run(function () use ($requester, $data): TransportRequest {
            $request = TransportRequest::create(Arr::except($data, ['documents']) + [
                'request_number' => $this->nextNumber(), 'requester_id' => $requester->id, 'status' => TransportStatus::PendingApproval,
            ]);
            if (! empty($data['documents'])) {
                $this->evidence->attach($request, $data['documents'], $requester->id, TransportRequest::DOCUMENT_PURPOSE);
            }
            $this->log($request, $requester, 'SUBMITTED');

            return $request;
        });

        $this->notifyMany($this->approvers($requester), $request, 'TRANSPORT_SUBMITTED', 'New Transport Request',
            $requester->name.' requested transport to '.$request->destination.' on '.$request->travel_date->format('F j, Y').' ('.$request->request_number.').');

        return $request;
    }

    /** @param array<string, mixed> $data */
    public function approve(TransportRequest $transport, User $actor, array $data): TransportRequest
    {
        $authority = TransportApprovalAuthority::tryFrom((string) ($data['approval_authority'] ?? '')) ?? TransportApprovalAuthority::Own;
        $note = filled($data['approval_note'] ?? null) ? trim($data['approval_note']) : null;
        if ($authority === TransportApprovalAuthority::Own && ! $actor->can('transport.approve')) {
            throw ValidationException::withMessages(['approval_authority' => 'You may approve only as instructed by the Campus Director or FMO Head. Select the instruction you received.']);
        }
        if ($authority->isInstruction() && $note === null) {
            throw ValidationException::withMessages(['approval_note' => 'An approval note is required when approving as instructed (for example, how and when the instruction was received).']);
        }

        $this->files->run(function () use ($transport, $actor, $data, $authority, $note): void {
            $locked = $this->lock($transport, [TransportStatus::PendingApproval], 'This request has already been decided. Refresh the page to see its current status.');
            $locked->update([
                'status' => TransportStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now(),
                'approval_authority' => $authority, 'approval_note' => $note,
                'approval_instructed_by' => $authority->isInstruction() ? ($data['approval_instructed_by'] ?? null) : null,
            ]);
            if (! empty($data['approval_evidence'])) {
                $this->evidence->attach($locked, $data['approval_evidence'], $actor->id, TransportRequest::APPROVAL_EVIDENCE_PURPOSE);
            }
            $this->log($locked, $actor, 'APPROVED', $note, ['authority' => $authority->value]);
        });

        $fresh = $transport->fresh();
        $this->notifyMany(collect([$fresh->requester]), $fresh, 'TRANSPORT_APPROVED', 'Transport Request Approved',
            $fresh->request_number.' to '.$fresh->destination.' was approved. A driver and vehicle will be assigned.');
        $this->notifyMany($this->dispatchers($actor), $fresh, 'TRANSPORT_FOR_ASSIGNMENT', 'Transport Request Ready for Assignment',
            $fresh->request_number.' to '.$fresh->destination.' on '.$fresh->travel_date->format('F j, Y').' needs a driver and vehicle.');

        return $fresh;
    }

    public function reject(TransportRequest $transport, User $actor, string $reason): TransportRequest
    {
        $this->files->run(function () use ($transport, $actor, $reason): void {
            $locked = $this->lock($transport, [TransportStatus::PendingApproval], 'This request has already been decided. Refresh the page to see its current status.');
            $locked->update(['status' => TransportStatus::Rejected, 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
            $this->log($locked, $actor, 'REJECTED', $reason);
        });
        $fresh = $transport->fresh();
        $this->notifyMany(collect([$fresh->requester]), $fresh, 'TRANSPORT_REJECTED', 'Transport Request Rejected', $fresh->request_number.' was rejected: '.$reason);

        return $fresh;
    }

    public function cancel(TransportRequest $transport, User $actor, ?string $reason = null): TransportRequest
    {
        $this->files->run(function () use ($transport, $actor, $reason): void {
            $locked = $this->lock($transport, [TransportStatus::PendingApproval, TransportStatus::Approved, TransportStatus::Assigned], 'This request can no longer be cancelled.');
            $locked->update(['status' => TransportStatus::Cancelled, 'cancelled_by' => $actor->id, 'cancelled_at' => now()]);
            $this->log($locked, $actor, 'CANCELLED', $reason);
        });

        return $transport->fresh();
    }

    public function assign(TransportRequest $transport, User $actor, string $driverId, string $vehicleId): TransportRequest
    {
        $driver = $this->eligibleDrivers()->firstWhere('id', $driverId);
        if (! $driver) {
            throw ValidationException::withMessages(['driver_personnel_id' => 'Choose an active FMO driver.']);
        }
        $vehicle = $this->eligibleVehicles()->firstWhere('id', $vehicleId);
        if (! $vehicle) {
            throw ValidationException::withMessages(['inspectable_asset_id' => 'Choose a vehicle that is active and not under repair, retired, or disposed.']);
        }

        $this->files->run(function () use ($transport, $actor, $driver, $vehicle): void {
            $locked = $this->lock($transport, [TransportStatus::Approved, TransportStatus::Assigned], 'Only an approved request can be assigned.');
            $this->assertNoOverlap($locked, 'inspectable_asset_id', $vehicle->id, 'inspectable_asset_id', 'This vehicle is already assigned to another trip during that period.');
            $this->assertNoOverlap($locked, 'driver_personnel_id', $driver->id, 'driver_personnel_id', 'This driver is already assigned to another trip during that period.');
            $locked->update(['status' => TransportStatus::Assigned, 'driver_personnel_id' => $driver->id, 'inspectable_asset_id' => $vehicle->id, 'assigned_by' => $actor->id, 'assigned_at' => now()]);
            $this->log($locked, $actor, 'ASSIGNED', null, ['driver' => $driver->user?->name, 'vehicle' => $vehicle->asset_code]);
        });

        $fresh = $transport->fresh(['driver.user', 'vehicle']);
        $this->notifyMany(collect([$fresh->driver->user]), $fresh, 'TRANSPORT_ASSIGNED', 'Trip Assigned',
            'You are assigned to '.$fresh->request_number.' ('.$fresh->route().') on '.$fresh->travel_date->format('F j, Y').' with '.$fresh->vehicle->name.'.');

        return $fresh;
    }

    /**
     * Save the driver's trip details; with $complete the trip record is closed.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveTrip(TransportRequest $transport, User $actor, array $data, bool $complete = false): TransportRequest
    {
        $this->files->run(function () use ($transport, $actor, $data, $complete): void {
            $locked = $this->lock($transport, [TransportStatus::Assigned], 'This trip is not open for driver updates.');
            $fields = Arr::only($data, self::TRIP_FIELDS);
            if (array_key_exists('vehicle_issue_observed', $fields)) {
                $fields['vehicle_issue_observed'] = (bool) $fields['vehicle_issue_observed'];
                if (! $fields['vehicle_issue_observed']) {
                    $fields['vehicle_issue_remarks'] = null;
                }
            }
            $merged = array_merge($locked->only(self::TRIP_FIELDS), $fields);
            $this->assertConsistent($merged, $complete);

            $before = $merged['odometer_before'] ?? null;
            $after = $merged['odometer_after'] ?? null;
            $fields['distance_km'] = $before !== null && $after !== null ? (int) $after - (int) $before : null;
            if ($complete) {
                $fields += ['status' => TransportStatus::Completed, 'completed_at' => now(), 'completed_by' => $actor->id];
            }
            $locked->update($fields);

            if (! empty($data['trip_documents'])) {
                $this->evidence->attach($locked, $data['trip_documents'], $actor->id, TransportRequest::TRIP_DOCUMENT_PURPOSE);
            }
            if (! empty($data['issue_photos'])) {
                $this->evidence->attach($locked, $data['issue_photos'], $actor->id, TransportRequest::ISSUE_PHOTO_PURPOSE);
            }
            $this->log($locked, $actor, $complete ? 'COMPLETED' : 'TRIP_UPDATED');

            if ($complete) {
                $this->odometer->record($locked->vehicle, (int) $after);
            }
        });

        $fresh = $transport->fresh(['requester', 'vehicle', 'driver.user']);
        if ($complete) {
            $this->notifyMany(collect([$fresh->requester]), $fresh, 'TRANSPORT_COMPLETED', 'Trip Completed', $fresh->request_number.' to '.$fresh->destination.' was completed.');
        }

        return $fresh;
    }

    /**
     * Apply the shared list filters (status, search, driver, vehicle, destination, travel dates).
     *
     * @param  Builder<TransportRequest>  $query
     * @return Builder<TransportRequest>
     */
    public function filter(Builder $query, Request $request): Builder
    {
        return $query
            ->when($request->filled('status') && TransportStatus::tryFrom($request->string('status')->value()), fn ($q) => $q->where('status', $request->string('status')->value()))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($inner) => $inner->where('request_number', 'like', $term)->orWhere('purpose', 'like', $term)
                    ->orWhere('origin', 'like', $term)->orWhere('destination', 'like', $term));
            })
            ->when($request->filled('requester'), fn ($q) => $q->whereHas('requester', fn ($u) => $u->where('name', 'like', '%'.$request->string('requester').'%')))
            ->when($request->filled('destination'), fn ($q) => $q->where('destination', 'like', '%'.$request->string('destination').'%'))
            ->when($request->filled('driver'), fn ($q) => $q->where('driver_personnel_id', $request->string('driver')->value()))
            ->when($request->filled('vehicle'), fn ($q) => $q->where('inspectable_asset_id', $request->string('vehicle')->value()))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('travel_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('travel_date', '<=', $request->date('to')));
    }

    public function perPage(Request $request): int
    {
        return in_array($request->integer('per_page'), [20, 50, 100], true) ? $request->integer('per_page') : 20;
    }

    /** @return Collection<int, FmoPersonnel> */
    public function eligibleDrivers(): Collection
    {
        $query = FmoPersonnel::with(['user', 'skills'])->where('personnel_status', 'ACTIVE')->whereNull('archived_at');
        if (Skill::where('name', 'Driver')->exists()) {
            $query->whereHas('skills', fn (Builder $skills) => $skills->where('name', 'Driver'));
        }

        return $query->get()->filter(fn (FmoPersonnel $person) => $person->isAssignable())->sortBy(fn ($person) => $person->user->name)->values();
    }

    /** @return Collection<int, InspectableAsset> */
    public function eligibleVehicles(): Collection
    {
        return InspectableAsset::with('assetType')->where('asset_class', 'VEHICLE')->where('lifecycle_status', 'ACTIVE')
            ->where('status', 'ACTIVE')->orderBy('asset_code')->get();
    }

    /** @param list<TransportStatus> $allowed */
    private function lock(TransportRequest $transport, array $allowed, string $message): TransportRequest
    {
        $locked = TransportRequest::query()->whereKey($transport->id)->lockForUpdate()->firstOrFail();
        if (! in_array($locked->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }

        return $locked;
    }

    private function assertNoOverlap(TransportRequest $transport, string $column, string $value, string $field, string $message): void
    {
        $window = $transport->window();
        $conflict = TransportRequest::query()->where($column, $value)->whereKeyNot($transport->id)->where('status', TransportStatus::Assigned->value)
            ->whereRaw('COALESCE(expected_departure_at, CAST(travel_date AS DATETIME)) < ?', [$window['end']])
            ->whereRaw("COALESCE(expected_return_at, TIMESTAMP(travel_date, '23:59:59')) > ?", [$window['start']])
            ->exists();
        if ($conflict) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /** @param array<string, mixed> $trip */
    private function assertConsistent(array $trip, bool $complete): void
    {
        $errors = [];
        $departed = $trip['actual_departed_at'] ?? null;
        $arrived = $trip['actual_arrived_at'] ?? null;
        if ($departed && $arrived && now()->parse($arrived)->lt(now()->parse($departed))) {
            $errors['actual_arrived_at'] = 'Arrival cannot be earlier than departure.';
        }
        $before = $trip['odometer_before'] ?? null;
        $after = $trip['odometer_after'] ?? null;
        if ($before !== null && $after !== null && (int) $after < (int) $before) {
            $errors['odometer_after'] = 'Odometer after travel cannot be lower than the odometer before travel.';
        }
        if ($complete) {
            $labels = ['actual_departed_at' => 'actual departure', 'actual_arrived_at' => 'actual arrival', 'odometer_before' => 'odometer before travel',
                'odometer_after' => 'odometer after travel', 'fuel_before' => 'fuel level before travel', 'fuel_after' => 'fuel level after travel'];
            foreach ($labels as $field => $label) {
                if (blank($trip[$field] ?? null) && ! isset($errors[$field])) {
                    $errors[$field] = 'The '.$label.' is required to complete the trip.';
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function nextNumber(): string
    {
        $year = (int) now(config('app.timezone'))->format('Y');
        DB::table('transport_number_sequences')->insertOrIgnore(['year' => $year, 'next_value' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $sequence = DB::table('transport_number_sequences')->where('year', $year)->lockForUpdate()->first();
        DB::table('transport_number_sequences')->where('year', $year)->update(['next_value' => $sequence->next_value + 1, 'updated_at' => now()]);

        return sprintf('TR-%d-%04d', $year, $sequence->next_value);
    }

    /** @param array<string, mixed>|null $meta */
    private function log(TransportRequest $transport, User $actor, string $event, ?string $note = null, ?array $meta = null): void
    {
        $transport->events()->create(['actor_id' => $actor->id, 'event' => $event, 'note' => $note, 'meta' => $meta]);
    }

    /** @return Collection<int, User> */
    private function approvers(User $except): Collection
    {
        return User::permission(['transport.approve', 'transport.approve_delegated', 'transport.dispatch'])->get()
            ->filter(fn (User $user) => $user->isApproved() && $user->id !== $except->id)->unique('id')->values();
    }

    /** @return Collection<int, User> */
    private function dispatchers(User $except): Collection
    {
        return User::permission('transport.dispatch')->get()
            ->filter(fn (User $user) => $user->isApproved() && $user->id !== $except->id)->unique('id')->values();
    }

    /** @param Collection<int, User|null> $users */
    private function notifyMany(Collection $users, TransportRequest $transport, string $event, string $title, string $message): void
    {
        $users->filter()->each(fn (User $user) => $user->notify(new TransportNotice($transport, $event, $title, $message)));
    }
}
