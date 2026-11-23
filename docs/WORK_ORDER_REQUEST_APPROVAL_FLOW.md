# Work Order Request, Screening, Clarification and Approval Flow

This document records the verified implementation. See `WORK_ORDERS.md` for the requester portal and `web/docs/work-order-personnel-preferences.md` for preferred personnel.

## State machine (`App\Enums\WorkOrderStatus`, enforced in `WorkOrderWorkflowService`)

```text
Requester submits ─▶ SUBMITTED
  begin-screening        (work_orders.screen)                 SUBMITTED → FOR_SCREENING
  request-information    (screen + request_information)       FOR_SCREENING → NEEDS_INFORMATION
  resubmit               (the requester only)                 NEEDS_INFORMATION → FOR_SCREENING
                                                              (→ FOR_ASSESSMENT when the clarification came from assessment)
  recommend-approval /
  recommend-disapproval  (screen + recommend)                 FOR_SCREENING → FOR_APPROVAL
  approve                (work_orders.approve)                FOR_APPROVAL → APPROVED   ("awaiting assignment")
  disapprove             (approve + disapprove, reason)       FOR_APPROVAL → DISAPPROVED (terminal)
  return-to-screening    (approve + return_to_screening)      FOR_APPROVAL → FOR_SCREENING
```

`APPROVED` is the assignment boundary: only users who may assign (`work_orders.assign`, `assign_direct`, `reassign`) see it in the assignment queue, and it stays unassigned until management adds personnel (`ASSIGNED`).

Rules enforced on the server for web and `/api/v1` alike (both call `WorkOrderWorkflowController::perform`):

- Each action has exactly one legal source status. The Work Order row is locked, the status re-read, and a mismatch returns HTTP 409. This covers skipped stages, repeated approvals (one `APPROVE` event, one notification), stale approvals and approving a disapproved request.
- Decisions append `work_order_workflow_events` rows (actor, action, from/to status, internal note, requester message, timestamp). Events are never updated or deleted. `decided_by` / `decided_at` record the final decision.
- Nobody may screen, recommend, approve, disapprove or return their own request (HTTP 403). Only `resubmit` is the requester's action. The Campus Director's explicit `work_orders.create_direct` action is the one intentional self-authorizing path and has its own `DIRECT_AUTHORIZATION` audit event.
- Requester, status, approval fields and assignments are never mass-assignable from a request body; `validated()` returns only whitelisted fields.

## Who can do what

| Role (default seed) | Create ordinary request | Screen / recommend | Approve / disapprove | Scope |
|---|---|---|---|---|
| Requester (faculty, staff, other non-FMO) | Yes | No | No | Own requests |
| Campus Director | Yes (plus explicit direct authorization) | No | No (default seed) | View all, read-only oversight |
| Director for Instruction | Yes | No | No | View all, read-only oversight |
| FMO Head | No | Yes | Yes | All |
| FMO Dispatcher | No | Yes (recommend, no final decision) | No | View all, assign |
| FMO Oversight | Per `work_orders.create` grant, never when linked as FMO personnel | No | No | Read-only |
| FMO Staff | No | No (unless delegated) | No (unless delegated) | Assigned work only |

Creation eligibility is decided by `WorkOrderAccessService::isFmoPersonnel()`: any retained personnel profile (any status, including archived) or an FMO Head / Dispatcher / Staff role blocks creation, whatever permissions or administrator roles are held. The same rule guards the form, `POST /work-orders`, `POST /api/v1/work-orders`, and both direct-authorization endpoints.

Approval and screening powers can be delegated by the FMO Head through direct permissions. A delegated user still cannot act on their own request.

## Clarification and resubmission

`request-information` requires a requester-visible message. The detail page and edit form show the latest message prominently. The requester edits permitted fields and uses `resubmit`, which keeps the same Work Order, number, history and prior attachments, appends a `RESUBMIT` event, revalidates the location hierarchy and preferred personnel against the (possibly changed) Work Type, and notifies reviewers.

## Preferred personnel

Advisory only: stored in `work_order_personnel_preferences`, validated server-side against the Work Type's mapped skills across **all** of the person's skills (`WorkOrderPreferenceService`), limited by `config('work_orders.preferences.max_personnel')` (default 3, the same value drives the UI), distinct and active. Preferences never create assignments, My Tasks entries, access or assignment notifications.

## Internal notes

Internal screening/approval notes and private workflow events (`RECOMMEND_*`, `RETURN_TO_SCREENING`, assignment and assessment events) are visible only to management who are not the requester of that Work Order. A Director with `view_all` who files a request sees it exactly as any requester does (web and API). Communication internal notes follow `CommunicationAuthorizationService`.

## Notifications (in-app, once per workflow event)

| Event | Recipients |
|---|---|
| `SUBMITTED` | Requester, users with `work_orders.screen` |
| `REQUEST_INFORMATION` | Requester |
| `RESUBMIT` | Users with `work_orders.screen` (assignees and assessment resolvers when the clarification came from assessment); never the requester |
| `RECOMMEND_APPROVAL` / `RECOMMEND_DISAPPROVAL` | Users with `work_orders.approve` (the wording does not reveal the recommendation); never the actor |
| `APPROVE` | Requester, users with `work_orders.assign` |
| `DISAPPROVE` | Requester |

Preferred personnel are never notified unless they are later assigned. Deliveries are deduplicated per workflow event.

## Attachments

Stored on the private `local` disk and served only through authorized routes. A user who cannot view the Work Order receives 403 for the original, thumbnail and download routes; an attachment from a different Work Order returns 404.
