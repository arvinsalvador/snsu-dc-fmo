# Work Order assignment and assessment (Phases 7–8)

## Assignment

An approved order enters `ASSIGNED` on its first active personnel assignment. Multiple active FMO personnel are supported. Adding/removing members writes append-only workflow history and retained assignment rows; the database permits only one active assignment per order/person. Removing the final person before assessment returns the order to `APPROVED` and the assignment queue. Personnel must be active, unarchived, and linked to an approved account. Preferred personnel is advisory only. Assignment does not start work.

FMO Head, Dispatcher, and System Administrator may assign/reassign. Campus Director can assign only a direct order they authored. Director for Instruction and FMO Oversight are read-only. Ordinary FMO staff can see only active assignments through My Tasks and guarded order detail/API endpoints; removed staff lose access. Management may inspect all orders according to existing `view_all` permissions.

Web routes: assignment queue, My Tasks, assignment add/removal on order detail. API: `GET /api/v1/work-orders/assigned`, `POST /api/v1/work-orders/{id}/assignments`, `DELETE /api/v1/work-orders/{id}/assignments/{assignment}`. Assignment posts accept `personnel_ids[]` and an optional internal `note`; removals require a `reason`.

## Pre-work assessment

Active assigned FMO personnel acknowledge `ASSIGNED` to move the order to `FOR_ASSESSMENT`. Each assignee may independently submit immutable assessment records with required findings, one outcome, optional resource notes, and optional private photos. Outcomes are `READY_FOR_WORK`, `NEEDS_INFORMATION`, `NEEDS_FURTHER_INVESTIGATION`, `NEEDS_MATERIALS`, `MATERIALS_UNAVAILABLE`, `BEYOND_FMO_SCOPE`, `EXTERNAL_ASSISTANCE_REQUIRED`, and `RECOMMEND_CANCELLATION`. A ready assessment leads to `READY_FOR_WORK`; an exception leads to `ASSESSMENT_REVIEW`. Neither status starts actual work.

Authorized management resolves review by proceeding, requesting further investigation, requesting information from the requester, holding for materials, referring externally, marking beyond FMO scope, or cancelling. Information requests reuse the requester correction form; resubmission resumes `FOR_ASSESSMENT`, not initial screening. Internal findings and notes never appear in requester-facing order history. Requester-facing messages are separate. Assessment photos remain on Laravel's private local disk and are served only after order and assessment-specific authorization. No inventory or external service integration exists yet.

Web: My Tasks, Assessment Review queue, order-detail assessment/review forms. API: `POST /api/v1/work-orders/{id}/assessment/acknowledge`, `GET|POST /api/v1/work-orders/{id}/assessments`, and `POST /api/v1/work-orders/{id}/assessment/resolve/{decision}`. Standard Laravel validation errors and bearer-token authentication apply. The management resolution operation uses `internal_note` and `requester_message` separately.

The server is authoritative. Client-supplied status, assignee identity, and assessment author identity are ignored. Assignment/assessment transitions lock the order row and reject stale state with HTTP 409. Assessment history is append-only; edits/deletes are not exposed. See [architecture](ARCHITECTURE.md) for future offline synchronization conventions.

Permissions: `work_orders.assign`, `reassign`, `remove_assignee`, and `assign_direct` govern assignment; `view_assigned` and `assess_assigned` govern personnel access; `view_assessments`, `resolve_assessment`, `cancel`, `mark_beyond_scope`, and `refer_external` govern review and exceptional decisions. FMO Head has the management set by default; delegated permissions may grant specific actions. Director for Instruction and FMO Oversight can review but not resolve by default. All actions still check current order state and active assignment where relevant.
