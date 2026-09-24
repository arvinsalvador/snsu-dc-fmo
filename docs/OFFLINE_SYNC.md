# Offline sync contract (Phase 10 in progress)

Laravel/MySQL is authoritative. SQLite is a per-user operational cache and durable outbox. The mobile client never downloads the all-work-order or full user directory endpoints. `GET /api/v1/mobile/bootstrap` returns a **complete assigned-only snapshot**, including task location, team summaries, assessments, sessions, and updates. There is no incremental cursor yet: `snapshot_at` is a full-refresh checkpoint, not a pagination cursor. A later scale phase can add an ordered change sequence.

The local schema version is 1: `orders` (server JSON, active flag, sync timestamp), `operations` (client UUID, order, type, payload, state, response mapping), `media` (client UUID, parent operation, persistent file path, upload state), and `metadata` (last snapshot and personnel ID). Offline-created assessments, sessions and updates are represented by their durable client operation ID until the server returns a ULID; server result JSON stores that mapping. SQLite files use the authenticated account ID in the filename and are opened only for that account. Access to retained files still depends on device/app sandbox security.

## Push then pull

1. Queue field action with a stable UUID and UTC client time. The same ID and logical payload are reused on every retry.
2. Process operations in creation order. A progress update referencing an offline Start Work waits for the server session ID from the Start Work result.
3. After a parent assessment/update is accepted, upload its queued media before later operations. End-session evidence rules can therefore see the uploaded current-session media. Media is not deleted on failure.
4. Fetch a complete authorized snapshot and reconcile server state. Missing assignments disappear from the active task list. A revoked task with unresolved local work remains retained only for recovery.
5. Retry transient failures; preserve 403/404/409/422 cases as conflicts. Do not auto-merge lifecycle conflicts. A conflict on an order prevents later queued actions for that order from being pushed.

The server serializes an account's operations and records `(user_id, client_operation_id)` uniquely. An identical retry returns the stored result; reusing an operation ID for different content returns HTTP 409. Media idempotency includes a SHA-256 of the uploaded bytes and its target. Do not regenerate IDs during retry. Operations use existing assessment/execution services so authorization and lifecycle rules remain server-side.

Example: `START_WORK (op A)` → server returns session ULID → `ADD_WORK_UPDATE (op B, session from A)` → `UPLOAD_MEDIA (media C, update from B)` → `END_WORK_SESSION (op D)`. If media C fails, op D must wait. If the server processed A but the response was lost, retrying A returns the same session ID without a second session.

An expired token pauses synchronization without deleting local records. Renew sign-in with the same account and retry. If management revokes an assignment, the server rejects subsequent writes; the device preserves content as a conflict and the next snapshot removes the task from active tasks. If a Work Order completes elsewhere, stale writes likewise cannot replace the server state.

Automatic triggers are manual Sync Now, login/start, resume, connectivity return, and a one-hour in-process timer. Native scheduled background execution is not implemented in this checkout. Even once implemented, Android/iOS may delay or suppress scheduled work; there is no exactly-hourly guarantee.

This foundation still needs device validation, a reliable native background scheduler, richer conflict recovery/export, and expanded automated Flutter storage/sync tests before Phase 10 can be marked complete.
