# Work Order screening and approval

```text
SUBMITTED → FOR_SCREENING → FOR_APPROVAL → APPROVED
                  ↓                 ├────────→ DISAPPROVED
           NEEDS_INFORMATION       └────────→ FOR_SCREENING
                  ↓
          requester resubmits → FOR_SCREENING

Campus Director direct request → APPROVED
```

Screening and final decisions are separate authorities. A screener can begin review, request additional information, and recommend approval or disapproval. Recommendations move the request to `FOR_APPROVAL` and record `APPROVE` or `DISAPPROVE` as recommendation context. Only an authorized final approver can approve, disapprove, or return the request for more screening. Disapproval requires a requester-visible reason; returning to screening requires an internal note. The Campus Director has an explicit direct creation action guarded by `work_orders.create_direct`. That action validates the same request data, uses the same numbering sequence, records the director as decision maker, and writes `DIRECT_AUTHORIZATION` to history.

All workflow actions use `WorkOrderWorkflowService` with explicit source and destination statuses. A row lock and database transaction keep each status update, decision metadata, and history event consistent. A stale action receives HTTP 409 and cannot overwrite a later decision. Generic request edits ignore submitted status fields and are allowed only while `SUBMITTED`. Requesters can change normal request fields and respond only through the `resubmit` action when `NEEDS_INFORMATION`.

`work_order_workflow_events` is append oriented. Each event records actor, action, previous and new status, time, and separate `internal_note` and `requester_message` fields. Requester views and API responses hide internal notes and recommendation or return events; authorized management sees the full history. Existing submitted requests receive an initial submission event during migration. No normal UI edits or deletes workflow events.

The default FMO Head and System Administrator have screening and final decision authority. The FMO Dispatcher can screen and recommend, but cannot finalize. The Campus Director can create direct requests and view all. The Director for Instruction and FMO Oversight can view all without final decision authority. Screening and approval permissions may be delegated through existing direct-permission management, with access to the matching queues and details.

Approval means ready for Phase 7 assignment. Phase 6 creates no assignment or staff workload records.
