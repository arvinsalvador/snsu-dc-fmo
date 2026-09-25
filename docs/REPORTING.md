# Dashboards and Work Order reporting

The web dashboard uses existing Work Order, assignment, workflow-event, update, and work-session records. It does not maintain editable report copies or performance scores. Management/oversight users receive status counts, a bounded needs-attention list, current active sessions, factual personnel open workload, and recently verified Work Orders. Requesters see their own counts; ordinary staff see only currently assigned counts. Historical assignments remain available in the staff history report, not the current dashboard. The full management widgets are not displayed to requester/staff. Oversight is read-focused; dashboard access does not grant workflow actions. The selected period filters submitted and verified-complete metrics; current-state cards intentionally remain current. Default period is the current Asia/Manila month. The attention list is limited to 12, active sessions to 10, recent completion to 8, and personnel workload to 30.

`/reports/work-orders` is the central paginated history. Search by Work Order number, status, category, campus, building, office/room/area, submitted range, and verified-completion range. Authorized management can also search requester name and historical assignee. The same validated filters and authorization scope are used for CSV export. A selected date range is limited to the configured maximum (currently 366 days). The page shows filtered total and completed counts; CSV streams 200-record chunks rather than loading the full result. CSV timestamps are `YYYY-MM-DD HH:mm:ss` in Asia/Manila. User-origin text is protected against spreadsheet-formula interpretation. Category, personnel, and location history reuse these filters, including links from personnel/building pages. Archived reference records remain usable for historical reports.

The browser-printable Work Order is at `/reports/work-orders/{id}/print`. It includes request/location/decision/assignment, completion and verification, evidence count, and safe public milestones. Management sees assessment and work-session details. Requester print excludes internal notes and staff-only assessment content. It represents authenticated system actions, not a handwritten signature. PDF export is not included; the browser's Print/Save as PDF action is available.

## Operational metric definitions

- **Current status count:** Work Orders in each current status, across the viewer's authorized scope. It is not filtered by the selected period.
- **Submitted this period:** Work Orders whose `submitted_at` falls within the inclusive selected Asia/Manila dates.
- **Verified complete this period:** Completed Work Orders whose `verified_at` falls within the inclusive selected dates.
- **Average submission-to-completion:** Arithmetic mean of `verified_at - submitted_at` in hours for Work Orders verified complete in the selected period; open records are excluded.
- **Open personnel workload:** Number of distinct non-terminal Work Orders with an active assignment to that person. This is a factual count, not a personnel ranking.
- **Time in current state:** Elapsed hours since the latest workflow state transition, falling back to the Work Order update timestamp where no transition is available. Configured attention thresholds in `web/config/reporting.php` only suggest review; they are not contractual SLAs.
- **Active session age:** Elapsed hours since `started_at` for sessions with no `ended_at`. The configurable stale indicator recommends review but never auto-closes a session.

The Laravel application currently writes its MySQL `DATETIME` values as Asia/Manila local wall time. Reports use that same convention for date boundaries and display. A future canonical UTC migration must convert existing values deliberately rather than simply changing the app timezone. There is no Redis, external BI service, or report cache dependency.

## Authorization

The history and export queries are server-scoped. A requester sees only their requests. Ordinary staff see Work Orders to which they have been assigned, including historical assignments; they do not get other personnel's history. Management-wide history requires both `reports.view_work_orders` and `work_orders.view_all`. Management CSV additionally requires `reports.export_work_orders`. Personnel and location management filters require their respective history permissions. Print access checks the same scoped query. A dashboard or report link does not substitute for these server-side checks.
