# Authentication and authorization

## Phase 5 Work Order permissions

Work order permissions are `work_orders.create`, `work_orders.view_own`, `work_orders.view_all`, and `work_orders.update_own_submitted`. Category permissions are `work_order_categories.view`, `work_order_categories.create`, `work_order_categories.update`, `work_order_categories.manage_status`, and `work_order_categories.delete`.

Requesters receive create, own-view, submitted-update, and category-view access. FMO Head, Campus Director, Director for Instruction, FMO Dispatcher, FMO Oversight, and System Administrator have the required all-request visibility according to their seeded role mappings. Ordinary FMO Staff do not receive all-request visibility automatically. Category create/update/status permissions are delegable through the existing authorization system.

## Phase 6 screening and decisions

The Phase 6 permissions are `work_orders.screen`, `work_orders.request_information`, `work_orders.recommend`, `work_orders.approve`, `work_orders.disapprove`, `work_orders.return_to_screening`, `work_orders.resubmit_own`, and `work_orders.create_direct`. A screener needs `screen` alongside information request or recommendation permissions; final disapproval and return actions also require `approve`. These capabilities are distinct from general `view_all`. Delegated reviewers gain access to their matching work-order queues and details. FMO Head may delegate screening or final decision permissions using the existing permission administration interface. The Campus Director's direct creation capability is not delegable by default.

Default role mapping: FMO Head and System Administrator have all workflow permissions; FMO Dispatcher can screen, request information, and recommend; Campus Director can create direct requests; all roles allowed to create normal requests can resubmit their own correction; Director for Instruction and FMO Oversight retain read-only oversight of other users' requests.

## Account lifecycle

Public registration collects full name, email, password, and institutional category (`student`, `faculty`, or `staff`). Category describes the person's university relationship; it does not grant application authority. Public registrations start `PENDING` with no role or permission. Approved applicants receive the `Requester` role. Passwords use Laravel's hashed cast.

An authorized reviewer may approve, reject, or request correction from a pending applicant. Rejection and correction require a reason. A correction request changes status to `NEEDS_CORRECTION`; the applicant can update only name, email, and category, then resubmit to `PENDING`. Review actions, previous/new statuses, reviewer, reason, and timestamps are retained in `registration_reviews`. Rejected applicants remain able to sign in to view their status. `SUSPENDED` and `DEACTIVATED` accounts also retain their history but cannot use protected routes. Approved accounts can use the Phase 2 dashboard.

Status and role are independent: status governs access, while roles and permissions govern capabilities. The `approved` middleware checks current status for web and API requests. Administrative suspension or deactivation revokes all existing API tokens. Direct database changes to status are outside the supported workflow and should be avoided.

## Permissions and roles

The `web` guard is the single Spatie Permission guard for both session and Sanctum users. Effective permissions are the union of permissions granted by all roles and direct user permissions. Default roles are Requester, FMO Staff, FMO Dispatcher, FMO Head, Campus Director, Director for Instruction, FMO Oversight, and System Administrator. Roles may be combined per user.

The Phase 2 permission catalog is in `web/config/authorization.php`. Campus Director, Director for Instruction, FMO Head, and System Administrator get registration review permissions by default. FMO Staff and FMO Dispatcher do not. The System Administrator receives all Phase 2 permissions and may manage custom roles, user roles, status, and direct permissions.

FMO Head may grant or revoke only permissions listed in `delegable_permissions`, only to approved users with the FMO Staff role. This includes selected registration review, personnel, category, location, and work-order review permissions. The restricted path rejects system administration permissions even if an HTTP request is manipulated. Only users with `permissions.manage` can grant any catalog permission. Role assignment is separate from direct delegation and requires `users.assign_roles`.

Core roles cannot be edited or deleted through the web interface. Custom roles may be created and edited by authorized administrators. Assigned custom roles cannot be deleted. Users cannot change their own status, roles, or direct permissions through administration routes. The last active System Administrator cannot be suspended or stripped of that role through the interface.

## Web and API authentication

Web forms use Laravel sessions, CSRF protection, validation, session regeneration on login, invalidation on logout, and login/registration throttling. Pending and other nonapproved users may see only their own status and correction screen. All administrative actions are authorized on the server.

API endpoints are under `/api/v1`:

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/auth/login` | Email, password, and `device_name`; issues a 30-day Sanctum bearer token only for approved accounts |
| GET | `/me` | Returns safe user identity, category, status, roles, and effective permissions |
| POST | `/auth/logout` | Revokes the current bearer token |

Send `Authorization: Bearer <token>` for protected API routes. A repeated login with the same device name replaces its previous token. Invalid credentials return 401; nonapproved accounts return 403 with status. API routes require a valid bearer token and check account status on each request. A device should store its token securely and treat it as a secret. Full device management and Flutter login UI belong to later phases.

## Initial administrator and seeding

Run `./vendor/bin/sail artisan db:seed --class=AuthorizationSeeder` after migrations. The seeder is repeatable and creates the catalog and default role mappings without creating a person. Then run `./vendor/bin/sail artisan app:create-admin` interactively. It asks for identity and a password and safely confirms before updating an existing email. No default password or real account is stored in source control.

## Personnel permissions

Phase 3 adds personnel, skills, and requester-profile permissions. FMO Head receives personnel and skills management rights; Campus Director and Director for Instruction receive personnel visibility; FMO Dispatcher receives personnel and skills visibility. `personnel.view`, `personnel.create`, `personnel.update`, `personnel.manage_status`, `skills.view`, `skills.create`, and `skills.update` are delegable operational permissions. `personnel.delete` and `skills.delete` remain protected from the restricted FMO Head delegation path.

## Location permissions

Phase 4 adds campus, building, and subordinate-location permission groups. All approved operational roles receive safe read access. FMO Head and System Administrator receive location management permissions. The FMO Head may delegate building and location view/create/update/status capabilities; campus administration and delete permissions remain protected.

## Phase 9 execution permissions

`work_orders.start_assigned`, `update_assigned`, `end_session`, and `submit_completion` belong to FMO Staff by default and still require an active assignment to the specific order. `work_orders.view_execution` belongs to FMO Head, Dispatcher, Campus Director, Director for Instruction, FMO Oversight, and System Administrator. FMO Head and System Administrator additionally have `verify_completion`, `return_for_work`, and `manage_sessions`. Dispatcher and oversight roles cannot verify by default. `verify_completion`, `return_for_work`, `manage_sessions`, and `view_execution` can be delegated by the FMO Head through the restricted approval path; all catalog permissions remain under System Administrator control. Campus Director verification is permission-based, not role-name hard-coded.

Requesters see only their own safe progress summaries; private technical updates/evidence require an active assignment or execution-view permission. A completed Work Order cannot be modified by ordinary staff. See [Work Order execution](WORK_ORDER_EXECUTION.md) for state and API rules.

## Phase 10 mobile authorization

The mobile bootstrap intentionally ignores management-wide view permissions: approved, active FMO personnel with `work_orders.view_assigned` receive only Work Orders with a current active assignment to their own personnel record. Shared Work Orders include only their assigned team summaries. A requester or management-only account receives 403. Every mobile mutation and media upload uses the existing server assignment/permission checks; local cached visibility is not authorization. Revocation removes the task from the next active mobile snapshot while unresolved local work is retained for manual recovery. See [mobile API](API_MOBILE.md).

## Phase 11 notifications and reports

Notifications are always recipient-scoped through the authenticated user's own relation; no separate `notifications.view_own` permission is needed. The reporting permissions are `dashboard.view_management`, `dashboard.view_oversight`, `reports.view_work_orders`, `reports.export_work_orders`, `reports.view_personnel_history`, and `reports.view_location_history`. FMO Head and Dispatcher receive operational dashboard/report permissions; Campus Director, Director for Instruction, and FMO Oversight receive read-focused dashboard/report permissions. System Administrator receives the full catalog. Requester and ordinary FMO Staff use existing own-request/assigned-work visibility, without management-wide reporting rights. Management history requires `reports.view_work_orders` **and** `work_orders.view_all`; CSV additionally requires `reports.export_work_orders`. Personnel/location filters require their dedicated history permissions. See [Reporting](REPORTING.md).
