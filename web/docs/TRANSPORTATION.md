# Transportation / Trip Requests

Transportation is a dedicated module. A Transport Request is **not** a Work Order: there is no Start Trip, Pause, or Stop, and no work session. The driver records the actual trip details whenever convenient and then completes the trip record.

```text
Requester submits request → Approval → Driver + Vehicle assigned → Trip happens
   → Driver updates trip details (any time) → Driver completes trip → Vehicle Travel History
```

## Request

Navigation: **Transportation → New Transport Request / My Trip Requests / Trip Requests / Trip History** (visibility follows permissions).

The requester provides purpose, origin, destination (free text, never locations), travel date, optional expected departure/return date-time, number of passengers, optional passenger details and remarks. A supporting memo, travel order, authority to travel, or office order (PDF/JPG/PNG/WebP) is **optional** and never blocks submission.

Request numbers use `TR-YYYY-NNNN`, generated from a locked per-year sequence (`transport_number_sequences`), so concurrent submissions cannot collide.

Statuses: `PENDING_APPROVAL`, `APPROVED`, `ASSIGNED`, `COMPLETED`, `REJECTED`, `CANCELLED`. Every transition is recorded in `transport_request_events` (actor, note, time), so rejections and cancellations stay in the history.

## Approval

| Role | Permission | Can |
|---|---|---|
| Campus Director, FMO Head | `transport.approve` | Approve under own authority, approve as instructed, reject |
| FMO Dispatcher | `transport.approve_delegated` | Approve **only as instructed** by the Campus Director or FMO Head, reject |

Nobody can approve their own request.

**Delegated approval.** The approver chooses the *approval authority*: *Under own authority*, *As instructed by Campus Director*, or *As instructed by FMO Head*. For an instruction an **approval note is required** (for example "Approved as instructed via Messenger"); the name of the person who instructed is optional; supporting evidence (screenshot, memo, PDF) is optional. The record stores the actual user who clicked Approve (`approved_by`) separately from the authority (`approval_authority`, `approval_instructed_by`); the Campus Director is never recorded as the approver. Rejection requires a reason.

## Assignment

Users with `transport.dispatch` (FMO Head, FMO Dispatcher) assign a driver and vehicle to an approved request, and may change them until the trip is completed.

- **Driver:** an active, approved FMO personnel record with the **Driver** skill (any active personnel when no Driver skill exists).
- **Vehicle:** an Asset with class **Vehicle** whose lifecycle is Active and status is Active, so vehicles under repair, retired, or disposed are excluded. Vehicles remain ordinary Assets; there is no separate vehicle registry.
- **Overlap:** the same vehicle or driver cannot be assigned to two trips whose expected windows overlap (a trip without times covers its whole travel date).

## Driver trip update and completion

The assigned driver (`transport.update_assigned_trip`) can open the request and **Save Update** at any time, with partial data. Fields:

- actual departure and arrival (date **and** time; arrival may be on a later date; arrival must not precede departure)
- odometer before/after (after must not be lower than before); **distance is calculated** by the system
- fuel before/after: `EMPTY`, `1/4`, `1/2`, `3/4`, `FULL` (stored as controlled values, shown with a fuel bar)
- optional trip remarks, optional trip ticket or supporting documents
- optional **vehicle issue observed** with remarks (required once ticked) and optional photos

**Complete Trip** (`transport.complete_assigned_trip`) requires actual departure, actual arrival, odometer before/after, and fuel before/after. Remarks and attachments stay optional. Validation is server-side; a failed completion leaves the trip assigned.

Completion records the final odometer on the vehicle (see below). A completed trip is read-only.

If a vehicle issue was reported, management (`transport.dispatch`) can create **one** corrective Work Order from the completed trip. It follows the normal Work Order workflow and is linked through `work_orders.transport_request_id`; repeating the action returns the existing Work Order.

## Vehicle Assets

Vehicles are registered in the Asset Registry with asset class **Vehicle**. Seeded asset types: Van, Utility Vehicle, SUV, Pickup, Motorcycle, Other Vehicle. Make and model use the existing Brand and Model fields. Vehicle-specific data uses the extensible `specifications` JSON column (`plate_number`, `year`, `passenger_capacity`) plus `current_odometer_km` and `odometer_updated_at`.

**Current odometer.** A completed trip raises the vehicle's current odometer to the trip's final reading. It only ever moves forward, so a stale or backdated trip never lowers it. Managers can correct the reading from the asset form.

**Travel History.** The vehicle's asset page lists completed trips (date, route, driver, purpose, odometer before/after, distance, fuel) straight from the trip records, for users with `transport.history`. The asset's Complete Maintenance History also shows an *Official Trip Completed* entry.

**Trip History** (Transportation menu) lists completed trips with server-side search, filters, and pagination (default 20, or 50/100).

## Documents and privacy

Files use the private local disk and the existing authorized delivery route (`routine-evidence.show`, also `/api/v1/transport-attachments/...`); there are no public URLs. Documents are grouped on the request page:

| Group | Uploaded by | Visible to |
|---|---|---|
| Request Documents | Requester | Requester, assigned driver, management (`transport.view_all`) |
| Approval Evidence | Approver | Management only |
| Trip Documents (incl. issue photos) | Driver | Assigned driver, management |

## Permissions

`transport.request`, `transport.view_own`, `transport.approve`, `transport.approve_delegated`, `transport.dispatch`, `transport.view_all`, `transport.update_assigned_trip`, `transport.complete_assigned_trip`, `transport.history`.

- All core roles can request and view their own requests.
- **FMO Staff** additionally update and complete trips assigned to them.
- **Campus Director**: approve, view all, history. **FMO Head**: approve, dispatch, view all, history. **FMO Dispatcher**: delegated approval, dispatch, view all, history. **FMO Oversight**: view all, history (read only).

A requester sees only their own requests; a driver sees only trips assigned to them. Other requests return 403.

## Notifications

Database notifications only: approvers and dispatchers on a new request; the requester on approval, rejection, and completion; dispatchers when a request is ready for assignment; the driver on assignment.

## API (`/api/v1`)

```text
GET    /transport-requests                     list (scoped, filterable, paginated)
POST   /transport-requests                     submit (multipart for documents[])
GET    /transport-requests/{id}                detail
POST   /transport-requests/{id}/approve
POST   /transport-requests/{id}/reject
POST   /transport-requests/{id}/assign
PATCH  /transport-requests/{id}/trip           save partial trip details
POST   /transport-requests/{id}/complete
GET    /transport-attachments/{attachment}/{variant?}
```

The API and the web pages call the same `TransportRequestService`, so the rules above are identical.
