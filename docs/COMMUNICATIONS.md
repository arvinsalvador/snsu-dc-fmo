# FMO Communications

The communication module provides three contextual communication surfaces:

- Work Order Messages for requester-visible coordination attached to a Work
  Order.
- Internal FMO Notes for private operational coordination.
- Contact FMO for general facilities inquiries that do not yet require a Work
  Order.

It does not provide general person-to-person chat. Communication records do not
execute or replace formal Work Order workflow actions.

## Visibility Matrix

| Communication | Requester | Assigned Staff | FMO Head | Dispatcher | Oversight |
|---|---:|---:|---:|---:|---:|
| Work Order Messages | Own Work Order | Active assignment | Authorized | Authorized | View permission |
| Internal FMO Notes | Never | Active assignment and permission | Yes | Yes | View permission |
| Contact FMO | Own thread | No default access | Yes | Yes | No default access |

System Administrators receive all configured capabilities. Campus Directors and
the Director for Instruction may view Work Order Messages within their existing
Work Order scope but do not reply by default.

## Work Order Messages

Messages belong to the Work Order rather than an individual employee. Current
assignment scope is evaluated on every request. A newly assigned staff member
can read the existing conversation, while a removed assignee loses access under
the normal Work Order rules.

The requester, an active assignee, the FMO Head, or the Dispatcher may send a
message when their capability and Work Order scope both allow it. Completed,
cancelled, and disapproved Work Orders retain their history in read-only form.
Messages never reopen or change a Work Order.

The latest 20 messages are loaded first and shown oldest to newest within that
window. Earlier pages can be loaded explicitly. Work Order lists show compact
per-user unread counts. Opening the Messages section advances that user's read
cursor without changing another participant's unread state.

Deterministic suggested replies populate the editor but never send
automatically. Requester and FMO suggestions are different and remain editable.

## Internal FMO Notes

Internal notes use a separate table, model, routes, controllers, and permission
checks from requester-visible messages. They are available only to actively
assigned and authorized FMO personnel, authorized management, and explicitly
permitted oversight users.

Internal notes never appear in requester Work Order detail, requester APIs,
requester notifications, requester-safe print output, or requester attachment
responses. They remain distinct from formal work updates and assessment
evidence. Closed Work Orders keep internal notes as read-only history.

## Contact FMO

Eligible requesters can create an inquiry in one of these categories:

- General Facilities Inquiry
- Question About Existing Work Order
- Urgent Facilities Concern
- Other

An existing Work Order question requires the requester to select one of their
own Work Orders. The form explains that repair, maintenance, and service
requests should be submitted as Work Orders for formal processing.

The requester sees only their own threads. FMO Head, Dispatcher, and System
Administrator roles can manage the shared inquiry queue. A management reply
sets the thread to `RESPONDED`; a requester follow-up returns it to `OPEN`.
Authorized management may close a thread. Closed threads retain their complete
history and become read-only.

Version 1 does not assign inquiries to field personnel or convert inquiries to
Work Orders. Management should direct the requester to create a Work Order when
formal work is required.

## Attachments

All communication attachments are private JPEG, PNG, or WebP images. Each
message accepts up to three images of up to 10 MB each by default. Images use
the existing normalized private-media pipeline, including dimension checks,
compression, thumbnails, transaction-aware cleanup, and inline lightbox
preview.

Attachment authorization is resolved from its parent communication record:

- Work Order message photos require Work Order message access.
- Internal-note photos require internal-note access.
- Contact FMO photos require ownership of the thread or inquiry-management
  access.

Message photos are communication context. They are not formal assessment,
execution, or completion evidence.

## Retention and Immutability

Messages and notes are append-only through the web and API surfaces. No edit or
delete routes are exposed. Sender identity and parent context are always taken
from authentication and route binding; request payloads cannot override them.
Foreign-key restrictions preserve history when users or parent records are
still referenced.

Historical messages continue to display when a sender account becomes inactive.

## Notifications

The database notification center supports these events:

- `WORK_ORDER_MESSAGE`
- `WORK_ORDER_INTERNAL_NOTE`
- `FMO_INQUIRY_CREATED`
- `FMO_INQUIRY_REPLY`

Requester Work Order messages notify active assignees and authorized FMO
management. FMO Work Order replies notify the requester. Internal notes notify
only relevant FMO participants and never the requester. New inquiries notify
inquiry managers; management replies notify the requester. Notifications include
a short preview, requester context when appropriate, and an authorized deep
link.

No Redis, Horizon, Pusher, Reverb, WebSocket, or polling dependency is used.

## Permissions

The module adds these capabilities:

- `work_orders.messages.view`
- `work_orders.messages.create`
- `work_orders.messages.manage`
- `work_orders.internal_notes.view`
- `work_orders.internal_notes.create`
- `fmo_inquiries.create`
- `fmo_inquiries.view_own`
- `fmo_inquiries.manage`
- `fmo_inquiries.reply`
- `fmo_inquiries.close`

Default mappings are:

- Requester: Work Order message view/create within owned Work Orders, inquiry
  create, and own-inquiry view.
- FMO Staff: Work Order message view/create and internal-note view/create while
  actively assigned.
- FMO Dispatcher and FMO Head: Work Order message management, internal-note
  view/create, and inquiry management/reply/close.
- Campus Director and Director for Instruction: Work Order message view within
  existing scope.
- FMO Oversight: Work Order message and internal-note view within existing
  scope.
- System Administrator: all configured capabilities.

Capability checks are always combined with Work Order or inquiry data scope.

## API

Authenticated `/api/v1` endpoints are available for future mobile clients:

```text
GET  /api/v1/work-orders/{workOrder}/messages
POST /api/v1/work-orders/{workOrder}/messages
GET  /api/v1/work-orders/{workOrder}/internal-notes
POST /api/v1/work-orders/{workOrder}/internal-notes

GET  /api/v1/fmo-inquiries
POST /api/v1/fmo-inquiries
GET  /api/v1/fmo-inquiries/{thread}
POST /api/v1/fmo-inquiries/{thread}/messages
POST /api/v1/fmo-inquiries/{thread}/close

GET  /api/v1/communication-attachments/{attachment}/{variant?}
```

Message collections are paginated at 20 records. Work Order collections expose
`unread_messages_count`. Internal notes are returned only by their dedicated,
authorized endpoints and are never embedded in requester Work Order resources.

## Security and Performance

The implementation enforces parent scope on every read, write, and attachment
request; validates body length and image type/count/size; escapes all message
body output; rate-limits send endpoints; and derives sender and parent IDs on
the server. Eager loading covers message senders and attachments, and list
unread counts use one grouped query instead of per-row queries.

Tests cover cross-requester and unassigned-staff IDOR attempts, reassignment,
requester exclusion from internal notes, requester-safe API/print output,
private attachment authorization, notification recipients, XSS output,
pagination, closed-thread behavior, and query growth.

## Flutter and Offline Work

Flutter UI and offline message synchronization are intentionally deferred.
Future mobile clients should use the explicit API endpoints and initially
require connectivity for message sends and attachment uploads. Offline replay
will require a separate idempotency and conflict policy before implementation.
