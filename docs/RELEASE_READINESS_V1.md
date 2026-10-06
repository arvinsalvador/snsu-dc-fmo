# Version 1 release-readiness assessment

Assessment updated 2026-10-04. Suggested designation: **`v1.0.0`**; no tag or deployment was made.

## Decision

**NOT READY — BLOCKERS REMAIN** for the complete web-plus-field-mobile Version 1. The Laravel backend/web candidate passes its automated checks, but the Flutter SDK is unavailable on this machine and the repository still lacks generated Android platform files. The changed mobile source, Android backup policy, build, and actual offline/device behavior therefore cannot be validated. This is a technical/mobile gate, **not** a conclusion that development is incomplete merely because formal human UAT has not happened. Web-only UAT can proceed on disposable test data; this report does not approve a complete Version 1 mobile UAT or pilot.

## Verified technical results

| Check | Result |
| --- | --- |
| Complete Laravel suite after release fixes | **67 passed, 595 assertions**, no failures/skips reported |
| Security-sensitive coverage | Included registration injection, RBAC/delegation, requester/assigned-only scope, evidence privacy, workflow transitions, direct authorization, notification/report scope, and mobile idempotency/conflicts |
| New rollback/history regression cases | Nested private-file rollback, mobile media ledger failure cleanup, personnel-with-assignment-history delete denial, and inactive-personnel read-scope denial passed |
| Laravel Pint | 12 changed PHP files passed `--test` |
| Vite frontend production build | Passed; optional font-fallback optimization notice only |
| Fresh disposable MySQL `testing` database | Database identity confirmed as `testing`; `migrate:fresh --seed --force` passed (21 migrations; authorization/reference seeders) |
| Fresh bootstrap | 8 roles, 84 permissions, 0 default users; `app:create-admin` command registered |
| Local Docker/HTTP | Laravel container up, MySQL healthy; `/api/v1/health` and `/login` returned 200 |
| Flutter analyze/tests/build/device | **Not run.** Neither Windows nor WSL resolves `flutter`/`dart`; `mobile/android` does not exist. Source changes are not marked verified. |

## Remaining gates

| Issue | Severity | Category | Current status and required next action |
| --- | --- | --- | --- |
| Flutter/Dart SDK unavailable | HIGH | Environment | **Open technical gate.** Install a stable SDK on an owner-controlled development machine, then run format/analyze/tests. Do not treat absent tooling as a failing Flutter test. |
| Android platform project/build absent | HIGH | Code | **Open technical gate dependent on SDK.** Generate/review `mobile/android`, resolve dependencies and produce a debug/test build. Do not claim the current Dart-only checkout is a buildable Android release. |
| Mobile offline, account isolation, assignment revocation and conflict recovery on an actual device | HIGH | Manual Verification | **Not run.** Execute UAT M01–M05 on disposable accounts, including kill/restart, token expiry, media retry and shared-device switch. Required before field-mobile UAT sign-off/pilot, not a backend code failure. |
| Android backup and local-data protection policy | HIGH | Code | **Open technical/security gate for real field data.** After platform generation, settings must exclude private SQLite/evidence/secure-storage data from backup, or an approved encrypted-backup policy must be demonstrated. SQLite itself is not encrypted; review the device threat model. |
| Formal stakeholder UAT and sign-off | HIGH | Manual Verification | **Not run.** Complete [UAT cases](UAT.md), log defects and retest. This is the next acceptance activity, not by itself an implementation defect. |
| Actual host HTTPS/configuration and backup restore | HIGH | Manual Verification | **Not run.** Validate the real host and perform a non-production restore rehearsal per [production checklist](PRODUCTION_CHECKLIST.md) before pilot. No hosting credentials or deployment were used. |
| Representative staging performance | MEDIUM | Manual Verification | **Not measured.** Test authenticated pages, reports and complete assigned-task bootstrap with realistic volumes before pilot; see [baseline](PERFORMANCE_BASELINE.md). |

## Resolved during release hardening

- Private upload paths are now tracked across nested database transactions and deleted if the transaction/outer idempotency-ledger write fails. Regression cases pass. This prevents the known orphan-file failure mode; it does not replace routine storage reconciliation/backup controls.
- Deleting personnel with Work Order history now returns HTTP 409 with archive guidance instead of exposing a foreign-key error; historical records remain intact.
- Inactive/archived personnel no longer retain assigned-task detail/list or report/print access merely because their assignment row is still active. The staff activity paths already rejected their assessment/execution writes; a new read-scope regression case covers the remaining gap.
- The Flutter outbox now blocks later actions on the same Work Order behind **any** earlier unresolved action/media, not only a declared conflict. A regression test was added but cannot run until the Flutter SDK is available.
- Mobile account ID and API token now share one secure-storage record, preventing an interrupted split-key write from pairing one account's local database with another account's token. Legacy split-key sessions are deliberately not resumed; local data remains for same-account reauthentication. Device verification is still required.
- Flutter source now has a minimal API notification list/read screen and copyable local issue details. These are source changes only; widget/API/device behavior is unverified. Media bytes are not exported by the copy action.
- The previous statement that formal UAT and production-host work made *development implementation* incomplete was overbroad. They are acceptance/deployment gates. Native exact-hourly background sync, push alerts, inventory, stock, assets/QR, preventive maintenance and AI are not Version 1 release gates.

## Known Version 1 boundaries, not current blockers by themselves

The mobile timer runs approximately hourly only while the app process is active, plus launch/resume/connectivity/manual triggers; mobile operating systems do not guarantee exact-hourly background execution. The bootstrap remains a complete assigned-only snapshot without an incremental cursor; staging-size measurement is required before pilot, but no current failure is demonstrated. Conflicted text remains in the per-account outbox and can be copied from the issues screen; a polished media export/recovery workflow is not present. No inventory, material stock deduction, asset/QR, preventive-maintenance, push-notification or AI module is included. Formal UAT fields remain blank until people actually test.
