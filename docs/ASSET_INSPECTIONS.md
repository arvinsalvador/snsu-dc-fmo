# Asset Inspections and QR

## QR identity

Every asset receives a stable random 48-character token. Its QR contains only the authenticated `/assets/scan/{token}` URL—never staff identifiers, asset JSON, history, or secrets. The token locates a record but grants no authority: normal login, account approval, and `assets.view` checks still apply. Authorized management can print a compact label or regenerate a compromised token; regeneration invalidates the previous label and creates a lifecycle audit event.

The scan screen shows code, type, photo, location, current condition, lifecycle, and last inspection. A scan may list several open inspection items when schedules overlap. Each option shows its schedule and due/overdue state. The QR token remains identification only: the server enables **Start Inspection** only when the signed-in user has `assets.inspect`, has `inspections.perform`, and is an active assignee of that exact item. Management-wide visibility never implies permission to perform an inspection.

An unassigned user receives a safe asset overview and the explicit message **Inspection not assigned to you** rather than a 403 or an action button. An assigned user who lacks either capability sees the assignment but cannot start. Asset-code search is the no-camera fallback; no large browser scanner dependency is required.

## Recurring inspection scope and assignment

An inspection template can cover specific assets, every active asset of a type, or active assets of a type in a selected location. Specific selections are stored explicitly. Type-based scopes are resolved whenever a future occurrence is generated, so newly registered matching assets join future runs without changing an already-generated run.

The template's default team applies to every included asset unless **Customize assignment by asset** defines an override. Eligible personnel must be active, unarchived, linked to an approved user, and currently available. The generated `InspectionItem` stores its own active assignment rows, checklist snapshot, and asset-context snapshot. Editing the template team or scope therefore affects future runs only.

Managers with `inspections.assign` may reassign all unfinished items in a run or one unfinished asset. Replacement ends the previous assignment rows with actor, time, and reason and creates new rows; it does not rewrite history. Completed items cannot be reassigned. Run-level reassignment skips completed items and synchronizes the occurrence team to the union of current item assignees.

## Inspection records

`InspectionItem` is the permanent exact-asset record for recurring inspections. It stores inspector personnel/user, timestamp, condition, checklist results, summary, remarks, recommendation, private attachments, and the generated checklist/asset snapshots. A QR scan opens an authorized recurring item; it does not create an unplanned inspection silently. Every future scheduled occurrence creates new item history rather than overwriting an earlier inspection.

Condition choices use staff-facing explanations for pristine, good, needs attention, needs repair, broken, and disposal recommendation. Each checklist comes from the selected Asset Type rather than a hard-coded Blade form. Asset and optional placement photos use camera capture or file choice. Evidence is mandatory for repair, damaged/broken/unusable, and disposal recommendations.

Completing an inspection updates `last_inspected_at` and current condition. For a recurring run it also updates item progress; the occurrence completes only after every snapshotted asset item is inspected.

The inspection list and run page are server-scoped. Ordinary staff receive only items actively assigned to their personnel profile, including counts and overdue totals. Management with `inspections.view_all` can see all items, current assignees, assignment history, team progress, and who completed each asset. The performer is stored independently from assignment history. A completed item rejects duplicate submissions.

## Repair separation

Inspection assignment does not grant repair authority. Recording repair work requires both `assets.maintain` and an active assignment on the repair action. Managers with `assets.assign_maintenance` maintain that separate team and assignment history. Repair assignment changes notify added and removed personnel without changing inspection assignments.

## Future mobile work

Flutter remains deferred. The web and API contracts now expose assigned-inspector and action flags, scoped run items, multiple current inspection options, due state, and exact inspection-item IDs. A future client should consume those server decisions, cache only authorized items, resolve opaque QR tokens, stage checklist/evidence mutations offline, upload media through the existing private pipeline, and surface sync conflicts without weakening server authorization.
