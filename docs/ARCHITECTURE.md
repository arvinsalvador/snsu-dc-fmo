# Architecture

## Foundation

The system uses a Laravel web management platform and REST API backed by MySQL, plus a Flutter app for FMO personnel. Laravel Sail supplies local Docker development only; application logic remains portable to ordinary PHP/MySQL shared hosting. API routes are versioned under `/api/v1`; the initial unauthenticated endpoint is `GET /api/v1/health`.

Phase 2 adds session-based web authentication, Sanctum bearer-token API authentication, and Spatie roles and permissions. The shared `web` permission guard applies to both. Account status is checked separately from permission checks. See [authorization](AUTHORIZATION.md) for the lifecycle, delegation rules, and API contract.

Laravel conventions for later phases: models represent persisted records; controllers stay thin; Form Requests validate input; API Resources shape public responses; policies enforce authorization. Add Services/Actions only when business logic becomes complex, Jobs only for genuine background work, and Events/Listeners for meaningful domain side effects. Feature tests cover HTTP behavior; unit tests cover isolated logic. Successful responses should use normal Laravel JSON and validation should retain Laravel's standard API validation format unless a later documented need requires otherwise.

## Data, time, and storage

Laravel runs in `Asia/Manila` for user-facing operations. Synchronization data should use UTC, ISO-8601 machine-comparable timestamps at API boundaries and database timestamps consistently; convert for Philippine display where appropriate. The server will be authoritative for accepted records and timestamps.

Files use Laravel's filesystem abstraction. Database records store media metadata; protected requester and staff evidence is stored on Laravel's private `local` disk and served only by authorized download routes. Business logic should not assume files permanently remain on one local disk, preserving a future path to S3-compatible/object storage. Private evidence is never exposed through a public storage link.

## Offline-first design and remaining validation

Work Orders, assessments, sessions and updates use ULIDs; mobile operations/media use client-generated UUIDs and a server-side idempotency ledger. The mobile outbox records local creation time and sync status, queues media separately, and reconciles accepted server IDs. The server is authoritative for workflow state and authorization, with explicit conflicts rather than silent last-write-wins. A general per-record version field and incremental sync cursor are not implemented. Current MySQL timestamps are written as Asia/Manila wall time; API timestamps carry offsets/ISO-8601. Do not change database timezone conventions without a deliberate data migration.

The Phase 10 Flutter source now adds a per-account SQLite cache/outbox and secure bearer-token storage. `API_BASE_URL` is still supplied with `--dart-define`, allowing local, device, and production endpoints without committing a URL. See [offline sync](OFFLINE_SYNC.md) for the implemented full-snapshot/idempotency contract and current limitations.

## Future business context

The lifecycle implemented through Phase 9 is: requester submission → FMO screening → approval/disapproval → skilled personnel assignment → staff assessment/investigation → ready for work or management exception review → explicit Start Work and individual sessions → completion submission → FMO verification or return for additional work. Assignment and assessment are not work execution. See [assignment and assessment](ASSIGNMENT_ASSESSMENT.md) and [work execution](WORK_ORDER_EXECUTION.md).

Implemented policy includes direct Campus Director authorization, Director for Instruction oversight, approval-gated registration with delegable permission-based approval, delegable building/office management, requester-only and assigned-staff visibility, permission-scoped oversight, and multiple personnel per Work Order. Staff assessment precedes work; evidence may include initial/progress/outcome images. Private file writes are tracked through nested transactions so a later database/operation-ledger failure removes newly stored unreferenced files. Mobile source attempts synchronization on launch, resume, connectivity return, manual request, and about hourly **while the app is running**; Android platform generation, backup-policy review and device validation remain outstanding. Materials are optional and inventory integration belongs to a later version.
