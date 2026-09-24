# Work Order execution (Phase 9)

Assessment is not work. An active assigned, approved FMO staff member must explicitly select **Start Work** on a `READY_FOR_WORK` order (or a resumable order) before recording updates or evidence. The first session moves the order to `IN_PROGRESS`; another assignee may start an independent session on the same order. A person can have only one active session across all orders. The service locks the personnel row and the database has a unique `(fmo_personnel_id, active_marker)` constraint to prevent concurrent active sessions; ended rows set the marker to null and remain historical.

Each session has start/end timestamps, its own assignment/personnel link, an end outcome, and an immutable series of individually attributed updates. Updates can be Before, Progress, Issue, Completion, or Other. Session-ending actions create corresponding Continuation, Pause, Waiting for Materials, Investigation, or Completion updates. Other team members may read shared order activity but cannot edit one another's sessions. Staff removed from an active assignment lose future execution access; an active session must be ended or administratively resolved with a reason before removal.

Session end outcomes are `CONTINUATION`, `PAUSED`, `WAITING_FOR_MATERIALS`, `NEEDS_FURTHER_INVESTIGATION`, `CONTRIBUTION_COMPLETE`, and `WORK_ORDER_COMPLETION_SUBMITTED`. Continuation means normal work remains for another period and requires a description of remaining work. Pause means an interruption and requires a reason. Waiting for materials requires a textual resource description; there are no inventory transactions. Investigation requires evidence and management review. A completed individual contribution does not complete the overall order. If other team sessions remain active, the order stays `IN_PROGRESS`; the final ended session determines the next non-completion state. Stale sessions are never auto-closed. A manager with `manage_sessions` may force-end one only with an audited reason.

Completion requires a completion update from a work session, a completion summary, a description of work performed, and **no active team sessions**. Completion evidence is required by default; when a photo has no meaningful value, the submitter must provide a specific non-photo justification (minimum 20 characters), which is retained. The same operation can be selected as the session end outcome. Before/initial evidence is supported but optional by default. These requirements can be adjusted locally with `WORK_ORDER_REQUIRE_BEFORE_EVIDENCE`, `WORK_ORDER_REQUIRE_CONTINUATION_EVIDENCE`, and `WORK_ORDER_REQUIRE_COMPLETION_PHOTO`. Completion submission moves to `FOR_VERIFICATION`, not `COMPLETED`.

An authorized verifier can verify `FOR_VERIFICATION → COMPLETED` or return it `FOR_VERIFICATION → FOR_CONTINUATION` with a reason. Returned work uses a new session and retains all prior activity. An execution-stage investigation moves to `NEEDS_INVESTIGATION`; a manager with assessment-resolution permission may release it back to `READY_FOR_WORK` after active sessions are resolved. Completed orders reject new staff work. Reopening completed orders is deferred and would require a separate, auditable management action.

Each return for additional work increments an execution-cycle number. A subsequent submission needs a completion update and photo (or justified non-photo exception) from that new cycle; older evidence cannot silently satisfy the new submission.

Evidence is stored through Laravel's private filesystem; MySQL holds metadata linked to the order, session, update, and staff actor. Default upload limits are inherited from `WORK_ORDER_ATTACHMENT_MAX_COUNT` and `WORK_ORDER_ATTACHMENT_MAX_SIZE_KB`. JPEG, PNG, WebP, and short MP4 uploads are accepted. Submitted records and evidence cannot be silently edited or deleted. Requesters see only optional requester summaries, safe status/history, and the final work-performed summary; execution evidence remains private by default. No inventory, notifications, payroll timer, or Flutter offline synchronization is implemented here.

The web UI offers Active Work and For Verification queues, staff task controls, and a combined individual session/update view. The versioned API offers:

- `GET /api/v1/me/active-work-session`
- `GET /api/v1/work-orders/{id}/sessions`
- `POST /api/v1/work-orders/{id}/start-work`
- `POST /api/v1/work-orders/{id}/sessions/{session}/updates`
- `POST /api/v1/work-orders/{id}/sessions/{session}/end`
- `POST /api/v1/work-orders/{id}/submit-for-verification`
- `POST /api/v1/work-orders/{id}/verify`
- `POST /api/v1/work-orders/{id}/return-for-work`
- `POST /api/v1/work-orders/{id}/release-investigation`
- `POST /api/v1/work-orders/{id}/sessions/{session}/force-end` (elevated permission)

Session and update identity are always derived server-side; status is never accepted from an arbitrary client payload. Critical transitions lock the order and reject stale actions. Phase 10 may build an offline client on these APIs, but retry idempotency and synchronization remain future work.
