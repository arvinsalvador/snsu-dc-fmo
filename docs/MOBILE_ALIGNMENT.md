# Mobile alignment audit

The Flutter application is intentionally field-focused and does not duplicate all web administration features. Laravel remains authoritative for authorization, assignment scope, workflow transitions, evidence validation, and conflict resolution.

## Alignment matrix

| Web / business update | Classification | Mobile action |
|---|---|---|
| FMO personnel cannot create ordinary requests | Already aligned | Mobile login and bootstrap remain FMO-field-only; no request creation route exists. |
| My Requests / My Tasks / All Work Orders terminology | Should update | Renamed the assigned task home to **My Tasks**. No management-wide list was added. |
| Active-assignment-only access | Already aligned / verified | Bootstrap continues to use `activelyAssignedTo`; task cache reconciliation removes inactive assignments from the actionable list. |
| Assignment removal with offline work | Already aligned | Server rejection becomes a conflict; local operation/media records remain recoverable while the task leaves the active snapshot. |
| Work Order detail redesign | Should update | Added phone-first status/next-action card, location, requester, team, assessment, progress, evidence, and pending-state hierarchy. |
| Current status and next action | Must update | Removed invalid assessment/start/completion actions and distinguishes Start, Join, Resume, Continue, and read-only verification states. |
| Location hierarchy | Should update | Shows building plus optional floor and room/area; campus remains secondary context. |
| Requester identity | Should update | Shows the safe requester display name on tasks, detail, and notifications. |
| Preferred versus assigned personnel | Already aligned | Mobile only shows the active assigned team; it has no preference selector. |
| Personnel skills | Should update | Assigned team payload can show designation and primary skill without adding preference controls. |
| Requester clarification/resubmission | Web-only | Flutter has no requester mode, so this workflow was not added. |
| Dependent request-location selectors | Web-only | No requester creation form exists in field mobile. |
| Assessment outcome UX | Should update | Enum values remain unchanged in queued payloads but now have readable labels and bounded Material fields. |
| Offline assessment/idempotency | Already aligned | Stable operation UUIDs, persistent payload/media, ordered push, and server idempotency are preserved. |
| Explicit Start Work | Already aligned | Opening detail never starts work. Start/Join/Resume remains an explicit action. |
| One active session per staff member | Must update / verified | The existing local provisional guard remains; the UI no longer offers Start Work during the user's active session, and Laravel revalidates it. |
| Active work prominence | Should update | Active work is surfaced first on My Tasks and has a dedicated action card with update/photo/end controls. |
| End-session outcomes | Should update | Shows readable current enum labels, including Submit for Verification, while retaining conditional validation fields. |
| Evidence camera and gallery | Must update | Keeps `image_picker`, supports camera and multi-select gallery, and attaches media to the latest assessment/update outbox operation. |
| Evidence compression/orientation | Must update | Picker requests a maximum width/height of 1920 and quality 82. The platform picker applies orientation during image processing; the server still validates/normalizes. |
| Durable media queue | Already aligned / improved | Processed files are copied into per-account app-support storage before SQLite media enqueue. |
| Evidence thumbnails/viewer | Should update | Local and protected server evidence use bounded thumbnails; tap opens a swipeable, pinch-zoom viewer with index and group. |
| Completion evidence dependency | Must update / verified | Earlier unresolved media blocks later completion operations for that order. Completion confirmation summarizes retained evidence and enforces a non-photo explanation when none exists. |
| Failed media retry | Already aligned | Files and queue rows are retained with Pending/Failed/Conflict states. |
| Notification volume UI/search | Should update / web-only split | Added All/Unread filtering and compact context; complex notification search remains web-only. |
| Notification requester context | Must update | API now additively supplies Work Order number, subject, and requester name, including fallback for older notification payloads. |
| Notification deep links | Must update | Tap marks read, refreshes assignment scope if needed, and opens only a currently cached assigned task; stale access gets a friendly unavailable state. |
| Notification unread count | Already aligned | Count continues to come from the server query, independent of the first loaded page. |
| Work History redesign | Web-only | No management history/report module was added. |
| Collapsible chevrons | Already aligned | Mobile uses native page sections rather than custom collapsible controls. |
| Form/button/status consistency | Should update | Added friendly labels, outlined fields, primary next action, secondary support actions, status chips, and stronger empty states. |
| Connectivity and auth expiry | Already aligned | Launch/login, resume, connectivity return, manual, and in-process periodic sync remain; same-account reauthentication preserves local work. |
| Same-device user isolation | Already aligned | Secure session pair, per-account SQLite, per-account evidence/cache folders, and logout blocking with unresolved data remain. |
| Management/admin modules | Web-only | Users, roles, approvals, master data, reporting, printing, CSV, and management dashboards remain on web. |

## Current mobile scope

Mobile is for approved FMO field personnel: assigned tasks, assessment, work sessions, progress updates, private photo evidence, continuation/completion, synchronization, conflicts, and operational notifications. It does not expose ordinary requester or administrative workflows.

## Evidence and protected media

Camera capture uses `image_picker.pickImage`. Gallery selection uses `pickMultiImage`. Both request a 1920-pixel maximum edge and quality 82 without upscaling. Result files are copied to private app-support storage under the authenticated account before a durable SQLite media row is created. Local thumbnails use a bounded decode size; synchronized evidence uses authenticated API thumbnail/original endpoints and a per-account private cache. Full-screen viewers support swipe navigation and pinch zoom. Laravel remains authoritative for MIME, size, authorization, normalization, storage, and thumbnails.

Evidence must belong to a saved assessment or work update. The sync engine accepts the parent first, uploads its media, and will not overtake unresolved media with a later action on the same Work Order. A completion update and its photos therefore reach the server before submission for verification.

## Offline and conflicts

SQLite schema version 1 remains sufficient because richer task/evidence fields live inside the cached JSON snapshot. No schema migration or database wipe is required. Stable client operation/media UUIDs survive restart. Interrupted Syncing rows return to Pending. Authentication expiry pauses upload without deleting content. Assignment removal, stale workflow state, missing sessions, and server validation produce recoverable conflicts with human-readable messages.

## API additions

`GET /api/v1/mobile/bootstrap` additively includes assigned-team designation/primary skill plus protected evidence metadata and authenticated endpoint paths. `GET /api/v1/notifications` additively includes Work Order number, subject, and requester name. Existing request fields, operation types, and `/api/v1` version are unchanged.

## Known limitations

- Native OS background scheduling is not configured; the hourly timer only runs while the process is alive.
- Notification delivery is an in-app authenticated list, not device push notification registration.
- Video evidence remains unsupported in Flutter.
- Local SQLite is app-sandboxed but not independently encrypted; secure device policy and backup exclusion remain deployment requirements.
