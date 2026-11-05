# Routine Tasks

Routine tasks model planned FMO duties and deliberately do not use the requester Work Order lifecycle. A `RoutineTaskTemplate` defines the schedule, location, guidance, evidence rule, and active FMO assignments. The scheduler creates a separate `RoutineTaskOccurrence` for every expected date or period.

## Recurrence

Version 1 supports `DAILY`, `WEEKLY`, and `MONTHLY`. Weekly templates store an ISO weekday (1 Monday through 7 Sunday); monthly templates use a day from 1 through 28. The schema keeps recurrence configuration as JSON so a later custom recurrence mode can be added without replacing the model.

Run `php artisan routine:generate` from Laravel's scheduler. The command defaults to a configurable 62-day look-back and 35-day look-ahead, so missed cron invocations are backfilled. A unique constraint on template plus period key makes generation idempotent. Configure shared hosting to run `php artisan schedule:run` once per minute. Override the windows with `ROUTINE_BACKFILL_DAYS` and `ROUTINE_LOOKAHEAD_DAYS` if necessary.

### Working days

FMO works Monday to Friday. Daily templates do not generate Saturday or Sunday occurrences. A weekly or monthly date that falls on a weekend moves to the next working day (Monday); the occurrence keeps the period key of its logical week or month, so the shift never creates a second occurrence in either period. The occurrence's `expected_date` and `due_at` use the shifted working day. When a newly started template's first-period catch-up falls on a weekend and the shift would cross into the next period, that catch-up is skipped because the next period's own occurrence already covers the same working day. A Friday occurrence that is still unresolved stays overdue through the weekend.

Creation of a new active template also generates the relevant current window immediately. For monthly schedules this includes the current month even when the configured day has already passed; for weekly schedules it includes the active week. This prevents a newly created current inspection from remaining invisible until the scheduler's next backfill. Future-start and inactive templates are still excluded.

## Occurrence lifecycle

Occurrences use explicit states: `UPCOMING`, `DUE`, `IN_PROGRESS`, `COMPLETED`, `OVERDUE`, `COMPLETED_LATE`, `PARTIALLY_COMPLETED`, `UNABLE_TO_COMPLETE`, and `WAITING_FOR_SUPPLIES`.

### Start availability

`UPCOMING` means visible for planning, not executable. Because the current schema has no separate `available_at` column or early-start setting, the canonical availability boundary is the occurrence's `expected_date` at `00:00:00` in `Asia/Manila`. `due_at` remains the completion deadline and is not used as the opening time. This leaves one central boundary that can later be replaced by an explicit availability field without changing every controller and view.

Before that boundary, My Routine Tasks shows the Upcoming badge, availability date, and a read-only **View Details** action. The detail page hides start, update, completion, and evidence controls. At the boundary, a not-yet-started task offers **Start Task**; in-progress work offers **Continue Task**; overdue unresolved work offers **Update Task**; completed work is read-only.

Web and API start/resolve endpoints recalculate availability on the server and return a friendly validation response for forged early requests. Client-provided actionability flags are never trusted. API occurrence resources include `available_at`, `is_startable`, and `is_actionable` for web/mobile presentation.

Unresolved work becomes `OVERDUE`; it is never deleted or silently skipped. The deterministic progression is upcoming before `available_at`, available/due from `available_at` through `due_at`, and overdue after `due_at` while unresolved. The user interface and management counts treat any past-due, non-final occurrence as overdue even when its earlier workflow label was `IN_PROGRESS`, `PARTIALLY_COMPLETED`, or `WAITING_FOR_SUPPLIES`. Staff must record a structured outcome, a readable summary, the authenticated performer, and a timestamp. Partial updates require remaining work. Unable/waiting updates require one of the configured reason codes. `URGENT_WORK` may include an optional Work Order reference. Required evidence is enforced before resolution.

## Assignments and history

Templates support one or many existing `FmoPersonnel` records. Template assignments are ended with `unassigned_at`, not overwritten. General routine-task access follows the template's current active team. Changing that team therefore updates access to unresolved general-task occurrences while retaining the generated occurrence snapshot for audit history. The actual performer is stored independently.

Inspection templates deliberately use a stricter snapshot model. They can target specific assets, all active assets of a type, or a type within a campus/building/location scope. A default team applies to all generated items, with optional per-asset overrides. Each generated item snapshots its checklist, asset context, and active assignees. Later template edits affect only future runs; managers use the run/item reassignment controls when current unfinished work must change. Reassignment ends old rows and creates new rows with actor, timestamp, and reason. Completed items are immutable.

An inspection schedule has one operational occurrence. Its linked `InspectionRun` is not a duplicate task: **My Routine Tasks** presents the scheduled-duty summary and assigned-scope progress, while **My Inspections** presents the item-level workflow. Both use the same occurrence/run, assignment snapshots, due state, and completion records. Selecting Continue Inspection from the routine list opens that linked run.

The management list loads assignees and their skills eagerly and calculates occurrence and unresolved-overdue totals with aggregate queries. Its centered Assign Team dialog supports personnel search, multi-selection, eligibility feedback, routine workload counts, and a management-only assignment note.

## Evidence and review

Routine evidence uses the existing `EvidenceMediaService` and `StoredFileTransaction`: private local storage, validated image normalization, thumbnails, and protected streaming. Evidence may be optional or required. `requires_review` is retained for management workflows without forcing Work Order verification onto every basic duty.

## API and future mobile work

Authenticated `/api/v1/routine-tasks`, `/api/v1/inspectable-assets`, `/api/v1/asset-types`, `/api/v1/inspection-runs`, and `/api/v1/guided-description-profiles` endpoints expose stable ULIDs, explicit statuses, assigned scope, structured action codes, and inspection links. Inspection collection/detail responses are filtered to the caller's active item assignments unless the caller has management-wide visibility. Asset and QR responses expose `is_assigned_inspector`, `can_inspect`, current-item/due data, and all open inspection options. Mutations repeat the permission-plus-item-assignment check and reject completed-item resubmission. Offline mutation queues, device media staging, conflict handling, and Flutter screens are intentionally deferred.
