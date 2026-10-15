# Mobile API additions

All routes are under `/api/v1` and require the existing approved-account Sanctum bearer token. Standard Laravel 422 JSON validation and 401/403/404/409 errors apply. Do not log tokens or upload raw error traces to the field UI.

| Method | Path | Contract |
| --- | --- | --- |
| GET | `/mobile/bootstrap` | Complete assigned-only snapshot; FMO personnel with `work_orders.view_assigned` only. Returns `data.personnel`, `data.orders`, `data.snapshot_at`. No incremental cursor. |
| POST | `/mobile/operations` | One authenticated field action; requires UUID `client_operation_id`, `type`, ULID `work_order_id`, optional `payload`, installation UUID, and client time. Returns `data` with server IDs/status. |
| POST | `/mobile/media` | Multipart `file`, UUID `client_operation_id`, `target_kind` (`ASSESSMENT` or `UPDATE`), target ULID, optional installation UUID/time. Returns attachment ULID. |

Supported operation types: `ACK_ASSESSMENT`, `SUBMIT_ASSESSMENT`, `START_WORK`, `ADD_WORK_UPDATE`, `END_WORK_SESSION`, `SUBMIT_COMPLETION`. Payload field names and enum values follow the existing Phase 8/9 assessment/execution services. The mobile client must upload required evidence before dependent end/completion operations. Server authorization always rechecks current assignment and role permissions; a cached assignment is not proof of current access.

For a continuation requiring evidence, a photo on a current-session `PROGRESS` or `ISSUE` update satisfies the separate-upload route. Further investigation requires a current-session `ISSUE` update with evidence. A `BEFORE` photo alone does not satisfy either rule.

An operation ID is immutable. Re-send identical type/payload/target with the same ID after an uncertain network response; do not generate a new one. Reusing it with different content returns 409. Media retries must use the same bytes and ID. Once a media upload is accepted, the original attachment ID is returned on retry. The server's `processed_client_operations` table stores only compact result JSON and request fingerprint, not uploaded file bytes.

The new routes do not change existing Phase 1–9 API routes. The bootstrap includes only task-related team summaries, not the personnel directory or unrelated Work Orders.

## Routine inspection authorization contract

The existing routine-operation endpoints now enforce per-item inspection scope:

- Inspection collections and run details return only actively assigned items for ordinary staff; management-wide viewers may receive all items.
- Inspectable-asset and QR responses include `is_assigned_inspector`, `can_inspect`, `current_inspection_item`, `inspection_due`, and `available_inspections` where applicable. Several open schedules for one asset are returned as separate options.
- Recording an item requires `assets.inspect`, `inspections.perform`, an active assignment to that exact item, and an unfinished item status. The same rule applies to web and API requests, and is rechecked inside the write transaction.
- QR tokens identify assets but never grant authority. An authenticated, unassigned user can receive the safe asset overview while mutation remains forbidden.
- Inspection assignment and repair assignment are independent. Repair recording requires `assets.maintain` plus an active repair-action assignment.

These response additions prepare a later Flutter update; no Flutter screens, offline inspection mutation queue, or camera workflow are included in this web delivery.
