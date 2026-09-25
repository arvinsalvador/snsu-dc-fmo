# In-app notifications

Phase 11 uses Laravel's database notification channel. Approved users see their own paginated notifications at `/notifications`, with an unread badge in the web header. The recipient can mark one or all as read; reading does not delete history. The authenticated API exposes `GET /api/v1/notifications`, `POST /api/v1/notifications/{id}/read`, and `POST /api/v1/notifications/read-all`. All reads and mutations begin from the authenticated user's notification relation. The API returns a page of safe fields and an unread count; it does not return arbitrary stored action URLs.

Notification types are centralized in `WorkflowNotificationService`. Authoritative workflow-event creation triggers the notification observer, so rejected or idempotently replayed mobile operations do not create another event or another notice. The stored workflow-event ID provides additional per-recipient duplicate protection. Registration approval is observed separately. Notification failures are logged and do not abort the underlying workflow; email and push are not required. No internal notes are copied into notification text.

| Event | Default recipients |
| --- | --- |
| Submitted | Requester; approved users with screening permission |
| Direct authorization | Requester |
| Request information or disapproval | Requester |
| Approval | Requester; approved assignment managers |
| Assignment | Requester; newly assigned person receives a separate assignment notice |
| Assignment removal | Removed person |
| Assessment exception / investigation | Approved users with assessment-resolution permission |
| Ready for work | Requester; current assignees |
| Waiting for materials | Requester; assignment managers |
| Completion submitted | Requester; users with verification permission |
| Completion verified or returned | Requester; current assignees |
| Cancellation | Requester |
| Registration approved | Approved applicant |

Management and oversight recipients are chosen by effective permissions, not merely by role names. Low-level progress updates do not generate notifications. Notification links still require normal Work Order authorization; a removed assignee's notice opens the scoped historical print record. The mobile app can retrieve its own notifications through the authenticated API; no FCM/push or separate offline notification queue is provided in Phase 11. Native Flutter retrieval UI remains unverified until the Flutter SDK is available. A future phase may add configurable preferences, push, and explicit retention after defining operational policy.
