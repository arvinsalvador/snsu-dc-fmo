# Routine Tasks

Routine tasks model planned FMO duties and deliberately do not use the requester Work Order lifecycle. A `RoutineTaskTemplate` defines the schedule, location, guidance, evidence rule, and active FMO assignments. The scheduler creates a separate `RoutineTaskOccurrence` for every expected date or period.

## Recurrence

Version 1 supports `DAILY`, `WEEKLY`, and `MONTHLY`. Weekly templates store an ISO weekday (1 Monday through 7 Sunday); monthly templates use a day from 1 through 28. The schema keeps recurrence configuration as JSON so a later custom recurrence mode can be added without replacing the model.

Run `php artisan routine:generate` from Laravel's scheduler. The command defaults to a configurable 62-day look-back and 35-day look-ahead, so missed cron invocations are backfilled. A unique constraint on template plus period key makes generation idempotent. Configure shared hosting to run `php artisan schedule:run` once per minute. Override the windows with `ROUTINE_BACKFILL_DAYS` and `ROUTINE_LOOKAHEAD_DAYS` if necessary.

## Occurrence lifecycle

Occurrences use explicit states: `UPCOMING`, `DUE`, `IN_PROGRESS`, `COMPLETED`, `OVERDUE`, `COMPLETED_LATE`, `PARTIALLY_COMPLETED`, `UNABLE_TO_COMPLETE`, and `WAITING_FOR_SUPPLIES`.

Unresolved work becomes `OVERDUE`; it is never deleted or silently skipped. Staff must record a structured outcome, a readable summary, the authenticated performer, and a timestamp. Partial updates require remaining work. Unable/waiting updates require one of the configured reason codes. `URGENT_WORK` may include an optional Work Order reference. Required evidence is enforced before resolution.

## Assignments and history

Templates support one or many existing `FmoPersonnel` records. Template assignments are ended with `unassigned_at`, not overwritten. Each generated occurrence snapshots its assigned team, preserving scope and history if the template changes later. Any team member may perform the shared occurrence; the actual performer is stored independently.

## Evidence and review

Routine evidence uses the existing `EvidenceMediaService` and `StoredFileTransaction`: private local storage, validated image normalization, thumbnails, and protected streaming. Evidence may be optional or required. `requires_review` is retained for management workflows without forcing Work Order verification onto every basic duty.

## API and future mobile work

Authenticated `/api/v1/routine-tasks`, `/api/v1/inspectable-assets`, `/api/v1/asset-types`, `/api/v1/inspection-runs`, and `/api/v1/guided-description-profiles` endpoints expose stable ULIDs, explicit statuses, assigned scope, structured action codes, and inspection links. Assigned personnel can start/resolve routine occurrences and submit item inspections through scoped API mutation routes. Offline mutation queues, device media staging, conflict handling, and Flutter screens are intentionally deferred.
