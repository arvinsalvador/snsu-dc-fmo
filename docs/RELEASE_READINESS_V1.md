# Version 1 release-readiness assessment

## Release candidate

Suggested designation: **`v1.0.0`**. No Git tag or deployment was made. Assessment: **NOT READY — BLOCKERS REMAIN**. The Laravel backend/web candidate passes the current automated suite, but the field-mobile and human acceptance gates have not been completed.

## Completed modules

Laravel web/API: approval-gated authentication; roles, permissions and delegation; requester/personnel/skills; campus/building/location reference data; Work Order request/screening/decision; direct Campus Director requests; multiple assignments; assessment/management review; individual sessions, updates and protected evidence; completion/verification; in-app notifications; management dashboards, scoped history, CSV and printable records. Flutter source: secure token storage, per-account SQLite cache/outbox, assigned-only snapshot, idempotent queued operations/media and conflict-preserving retries. The latter is **source implementation, not a verified device release**.

## Automated test and build summary

| Check | Actual result |
| --- | --- |
| Final full Laravel suite | **63 passed, 578 assertions** |
| Phase 12 security/hardening feature tests | Included in full run: 5 tests covering private-download IDOR/safe filenames, API pagination/scope, mobile evidence cap, CORS and inactive-assignee assessment denial |
| Laravel Pint on Phase 12 PHP files | **9 files passed** |
| Vite frontend build | **Passed**; optional font-fallback optimization notice only |
| Fresh `testing` MySQL database migrations + production-safe `db:seed` | **Passed** after fixing permission-cache refresh; repeat seed passed |
| Disposable rollback of final reporting-index migration and re-migrate | **Passed** |
| Local HTTP smoke | `/api/v1/health` and `/login` returned **200** |
| Flutter analyze/tests/Android build/device validation | **Not run:** Flutter and Dart SDKs unavailable in Windows and WSL environment |

The disposable database was explicitly confirmed as `testing` before `migrate:fresh`; the development database was not reset. See [Performance baseline](PERFORMANCE_BASELINE.md) for limited local observations. No enterprise load test or production-host test was performed.

## Security review summary

Reviewed authentication/status gates, Sanctum token expiry/revocation, registration field allowlists, seeded and delegated RBAC, requester/assigned-only access, workflow transition rules, active-session locking/unique constraint, private evidence routes and filename handling, mobile idempotency, notification/report scope, CSRF, CORS, environment examples and logging patterns. Existing tests exercise registration injection, status suspension, unauthorized workflow actions, attachment privacy, sync retries and report scope. Phase 12 fixes include: safe download headers for hostile filenames; denial of assessment by inactive personnel; per-record mobile evidence cap and aggregate initial-attachment cap; paginated/eager-loaded general Work Order API lists; explicit CORS origin allowlist; locked last-administrator checks; and permission-cache refresh for fresh seeding.

This is not a penetration test. No known critical backend privilege escalation was found in the reviewed paths, but real deployment configuration and device storage still need independent validation. Workflow and database integrity are protected by explicit state transitions, row locks, unique active-assignment/session keys, foreign keys and server-authored actor/status fields. Historical Work Order data is not duplicated into reporting tables.

## UAT status

[UAT.md](UAT.md) contains role-by-role cases and an offline restart/retry/account-switch scenario. **No formal human UAT was executed or signed off.** No Android emulator/physical-device test, responsive-browser visual review or accessibility audit was performed in this workspace. Automated HTTP tests are not a substitute for those checks.

## Deployment and recovery requirements

[Production checklist](PRODUCTION_CHECKLIST.md) covers supported PHP/MySQL hosting, HTTPS, secure cookies, private evidence storage, CORS, queue/scheduler choices, `APP_DEBUG=false`, backups and a restore rehearsal. Shared hosting was not accessed or configured. The owner must validate that the actual host supports the required PHP/extensions and private storage layout. The proposed backup set includes MySQL, private media, application code/version and protected `APP_KEY`/environment configuration; no restore rehearsal has yet occurred.

## Known limitations and outstanding risks

- **Release blocker — mobile:** Flutter SDK/platform project unavailable here; Flutter tests/analyze/build, app-kill/restart durability, account switching, assignment-revocation conflict and device security have not been verified. Native OS background scheduling, Flutter notification display and guided conflict recovery/export are incomplete. SQLite is sandboxed per account but not encrypted; generated Android backup exclusion still requires review.
- **Release blocker — acceptance/deployment:** formal human UAT, representative staging performance, production-host configuration, HTTPS verification and backup restore rehearsal remain undone.
- **Medium reliability:** filesystem writes made before a later database transaction failure may leave orphaned private files. No automatic orphan cleanup was added in this phase; monitor and reconcile safely, without deleting referenced evidence. Long-term storage/log capacity policy is also owner-dependent.
- **Scale boundary:** mobile bootstrap uses a complete assigned-only snapshot, not incremental pagination. General Work Order API lists and web reports are paginated/streamed, but staging-size bootstrap measurements are still needed.
- **Version boundary:** no inventory, stock deduction, asset/QR, preventive maintenance, push notifications, advanced BI or AI decisions. These are not hidden Version 1 features.

## Recommendation

**NOT READY — BLOCKERS REMAIN.** Continue with Flutter/device validation and formal UAT before considering a pilot. Do not interpret the passing Laravel suite as Version 1 release approval. Phases 10, 11 and 12 remain partially complete in [PHASES.md](PHASES.md).
