# Preventive Maintenance

Preventive Maintenance (PM) is the scheduled-maintenance layer for records in the existing `inspectable_assets` registry. It does not replace routine operational duties, inspections, or corrective Work Orders.

## Architecture

`Asset → PM Plan → PM Occurrence → Assignment Snapshot → Execution → Asset History`

A defect found during PM may create a linked corrective Work Order. The repair then follows the normal Work Order workflow and never becomes complete merely because PM found it. Only an assigned performer of that occurrence or a plan manager may create it, only after the occurrence is completed with a finding or recommended corrective action, and only once per occurrence: retries return to the PM record and its existing Work Order instead of creating a duplicate.

## Asset classes and systems

Assets support `EQUIPMENT`, `FACILITY_COMPONENT`, `UTILITY_SYSTEM`, `SAFETY_EQUIPMENT`, `VEHICLE`, and `OTHER`. An optional parent asset allows systems such as a water-desalination system to contain individually tracked pumps, filters, membranes, tanks, and other meaningful components. Each child retains its own code, QR, condition, lifecycle, inspections, PM, repairs, and photos. Parent history aggregates immediate child history.

## Plans and templates

Plans belong to one asset and define maintenance type, calendar frequency, interval, start/end dates, availability and due time, checklist, readings, evidence requirement, optional verification, and default personnel. A manager can save a new plan as a reusable template; selecting that template on another new plan preloads its schedule, checklist, readings, and evidence defaults. Templates retain recommended skill IDs while management remains responsible for choosing personnel. Completed occurrence snapshots never change with the template.

Frequencies are daily, weekly, monthly, quarterly, semiannual, annual, or a custom day/week/month interval. Meter-based triggers are future-ready through structured recurrence and reading data but are not scheduled in V1.

## Scheduling and working days

`pm:generate` creates occurrences idempotently using a unique plan/period key. Daily plans (and custom day-interval plans) skip Saturday and Sunday instead of shifting them, so Monday never receives extra daily occurrences. Weekly, monthly, quarterly, semiannual, annual, and custom week/month schedules that land on a weekend move to Monday, while the occurrence retains its original logical period. A first-period catch-up on a weekend start date is skipped when the shift would leave its period, because the next period already covers that Monday. Every occurrence, whatever its frequency, opens at the plan's availability time and is due at the plan's due time on the same scheduled working day; this same-day due window is intentional. Work not completed by then becomes overdue and counts against on-time compliance, even for quarterly, semiannual, and annual plans. Generation runs daily at 00:20 Asia/Manila. A lightweight hourly schedule refreshes availability/overdue state and sends notices, and web/API start requests refresh state again so availability is enforced accurately even between scheduler runs.

Occurrences snapshot the plan name, type, checklist, readings, evidence rule, verification rule, asset, and assignees. Editing a plan never rewrites completed records. Upcoming tasks are visible but the server rejects start requests before `available_at`.

## Assignment and access

Assignments use FMO Personnel IDs. Regular FMO Staff only see and perform assigned occurrences. Each occurrence receives the plan's team once, when it is generated; later scheduler runs never add people to existing occurrences. Changing a plan's team replaces the team on open occurrences that have not started, adds new members to in-progress occurrences without removing the person already performing the work, and leaves completed and cancelled occurrences untouched. FMO Head has full plan, assignment, verification, dashboard, and reporting access. Dispatchers manage schedules and assignments. Oversight and Campus Director roles receive broader read/report access without staff execution authority.

## Execution

Starting creates one execution record. Completion captures checklist results (`OK`, `NEEDS_ATTENTION`, `NEEDS_REPAIR`, `NOT_APPLICABLE`), structured readings, selected guided actions, editable summary, findings, work performed, before/after condition, materials, recommended corrective action, component replacements, and private photo evidence. The execution form reuses the existing guided-description component and deterministic sentence generator; no AI service is required.

Evidence uses the existing local private media pipeline with compressed originals, thumbnails, authorized delivery, and no public-storage URLs. Photos are categorized as Before, During, After, or Finding/Defect. PM may update physical condition but does not change lifecycle state automatically.

## History and compliance

Asset history combines PM, inspections, corrective Work Orders, repair records, condition/lifecycle events, and child-component history. It is paginated in the database across all sources, lists only completed or already-overdue PM (never future or cancelled occurrences), and omits lifecycle entries that only duplicate an inspection or PM completion without changing condition or lifecycle. The PM history CSV streams every matching row in date order. PM reports support server-side search; plan, maintenance type, asset type, location, personnel, status, and date filters; pagination; and filtered CSV export. The dashboard supports asset class, asset type, building, personnel, and date filtering.

On-time compliance is `completed on or before due_at ÷ occurrences due to date × 100`. Both counts use only occurrences whose `due_at` has passed; future and cancelled occurrences are excluded, so work finished early today does not inflate the rate before it is due. A late completion is complete but not on-time compliant.

## QR and notifications

Asset QR pages show current PM. Assigned staff can start or continue an available occurrence. Upcoming work shows its availability date without a start action; unassigned staff cannot perform it.

Assigned staff receive one due notice and one overdue notice per occurrence. The timestamp fields on each occurrence prevent daily notification spam.

## API

- `GET /api/v1/preventive-maintenance/plans`
- `GET /api/v1/preventive-maintenance/tasks`
- `GET /api/v1/preventive-maintenance/tasks/{occurrence}`
- `POST /api/v1/preventive-maintenance/tasks/{occurrence}/start`
- `POST /api/v1/preventive-maintenance/tasks/{occurrence}/complete`

The server applies the same assignment and availability rules to web and API requests.
