# Architecture

## Foundation

The system uses a Laravel web management platform and REST API backed by MySQL, plus a Flutter app for FMO personnel. Laravel Sail supplies local Docker development only; application logic remains portable to ordinary PHP/MySQL shared hosting. API routes are versioned under `/api/v1`; the initial unauthenticated endpoint is `GET /api/v1/health`.

Phase 2 adds session-based web authentication, Sanctum bearer-token API authentication, and Spatie roles and permissions. The shared `web` permission guard applies to both. Account status is checked separately from permission checks. See [authorization](AUTHORIZATION.md) for the lifecycle, delegation rules, and API contract.

Laravel conventions for later phases: models represent persisted records; controllers stay thin; Form Requests validate input; API Resources shape public responses; policies enforce authorization. Add Services/Actions only when business logic becomes complex, Jobs only for genuine background work, and Events/Listeners for meaningful domain side effects. Feature tests cover HTTP behavior; unit tests cover isolated logic. Successful responses should use normal Laravel JSON and validation should retain Laravel's standard API validation format unless a later documented need requires otherwise.

## Data, time, and storage

Laravel runs in `Asia/Manila` for user-facing operations. Synchronization data should use UTC, ISO-8601 machine-comparable timestamps at API boundaries and database timestamps consistently; convert for Philippine display where appropriate. The server will be authoritative for accepted records and timestamps.

Files will use Laravel's filesystem abstraction. Database records will store media metadata, while the files themselves must not be assumed to remain on one local disk. This preserves a future path to S3-compatible/object storage. Attachments and evidence are not implemented in Phase 1.

## Future offline-first design

Synchronizable records should use application-generated ULIDs or UUIDs rather than relying only on sequential database IDs. Future records and APIs should account for server timestamps, device-local timestamps, monotonically managed record versions, sync status, device identifiers, and conflict detection. Write operations must use idempotency keys so retries do not duplicate work. The mobile client will queue media uploads separately, retain local upload state, and reconcile them after record synchronization. The intended model is server-authoritative with explicit conflict handling, not silent last-write-wins.

The Flutter Phase 1 source has application configuration and a small core network client. `API_BASE_URL` is supplied with `--dart-define`, allowing local, device, and production endpoints without committing a URL.

## Future business context

The eventual lifecycle is: requester submission → FMO screening → approval/disapproval → skilled personnel assignment → staff assessment/investigation → proceed, information, investigation, materials, beyond-scope, or other exception → work → individual updates/evidence → completion → FMO verification/closure.

Future policy requirements include direct Campus Director authorization, Director for Instruction oversight, approval-gated registration with delegable permission-based approval, delegable building/office management, requester-only and assigned-staff visibility, permission-scoped oversight, and multiple personnel per work order. Staff assessment precedes work; evidence may include initial/progress/outcome images. Mobile will later support periodic (about hourly), manual, resume, and connectivity-triggered synchronization. Materials are optional and inventory integration belongs to a later version.
