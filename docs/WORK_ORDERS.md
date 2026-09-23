# Work Order Requests

Phase 5 introduces the requester-facing Work Order Request portal. An approved user can submit a category, campus and building, optional floor and specific location, subject, detailed description, requester-indicated urgency, optional preferred FMO personnel, and optional initial evidence.

Each request has a server-generated ULID primary key and a separate human-readable `WO-YYYY-######` reference. A yearly database sequence row is locked during creation, so references are unique without relying on `MAX(id) + 1`.

Normal requests start as `SUBMITTED`. Authorized Campus Director direct requests start as `APPROVED` with a distinct audit event. Requester identity and status are server controlled. A preferred FMO personnel member is only a preference: it is not an assignment, notification, access grant, or promise of assignment.

## Location and categories

Campus and building are required; a floor and an office, room, or area are optional. This permits building-wide requests. Server validation verifies the selected hierarchy and accepts only active records for new requests. Historical relationships remain intact when reference records later become inactive.

Categories are distinct from personnel skills. Authorized users can create, update, activate/deactivate, search, and safely delete unused categories. Referenced categories must be deactivated instead of deleted. The supplied defaults are general reference values only.

## Attachments and privacy

Initial requester evidence uses the `REQUEST_INITIAL` purpose. Up to three JPEG, PNG, WebP, or MP4 files are permitted by default, with a 10 MB per-file limit. The limits are configurable through `WORK_ORDER_ATTACHMENT_MAX_COUNT` and `WORK_ORDER_ATTACHMENT_MAX_SIZE_KB`; production PHP/web-server upload limits must also allow the chosen values.

Files are stored on Laravel's private `local` disk with generated filenames. Metadata is stored in MySQL. Downloads go through an authenticated, authorized application route; storage paths and public file URLs are not exposed.

## Visibility and editing

Requesters can see only their own requests. Users with `work_orders.view_all` can read all requests. Delegated screeners and approvers can access the records in their queues. Ordinary FMO Staff do not receive those permissions just by being FMO personnel. Requesters may edit a `SUBMITTED` request, or update and resubmit a `NEEDS_INFORMATION` request; protected requester, number, and status values are never client controlled.

## API

Authenticated, approved API endpoints are under `/api/v1`:

- `GET /work-order-categories`
- `GET /work-orders`
- `POST /work-orders`
- `GET /work-orders/{workOrder}`
- `PATCH /work-orders/{workOrder}`
- `GET /work-orders/{workOrder}/attachments/{attachment}`
- `POST /work-orders/direct` (authorized Campus Director flow)
- `POST /work-orders/{workOrder}/workflow/{action}` for explicit screening, decision, and resubmission actions

The work-order collection is permission-scoped to the requester unless the caller has `work_orders.view_all`.

## Deferred workflow

See [Work Order Workflow](WORK_ORDER_WORKFLOW.md) for Phase 6 statuses, permissions, history, and action rules. Assignment, assessment, execution, staff evidence, completion, materials, inventory, and offline synchronization remain future work.
