# Asset Repair and Disposal

Asset action requests preserve who requested review, the originating inspection when applicable, problem description, recommendation, evidence, reviewer, decision, reason, and timestamps. Lifecycle events separately preserve every transition.

## Repair

Staff with `assets.request_repair` may submit a repair review from an inspection or asset page. Users with `assets.review_repair` may mark it **Repairable — Approve for Repair**, **Not Repairable**, return it for more information, or reject it. Approval moves the asset to `UNDER_REPAIR`. Repair recording is a separate responsibility from inspection: a person needs both `assets.maintain` and an active assignment to that repair action. Users with `assets.assign_maintenance` can replace the repair team; ended rows retain the actor, timestamp, and reason. Only the asset's current approved repair (the latest one while it is `UNDER_REPAIR`) grants repair access. Recording **Repaired and serviceable** or **Not repairable** ends that repair team's assignments; **Partially repaired** keeps the team in place.

- Repaired/serviceable → condition `GOOD`, lifecycle `ACTIVE`.
- Partially repaired → condition `NEEDS_REPAIR`, lifecycle remains `UNDER_REPAIR`.
- Not repairable → condition `UNUSABLE`, disposal review is opened.

The **Not Repairable** decision is atomic: it closes the repair request, transitions the asset to `FOR_DISPOSAL_REVIEW`, and creates the authoritative pending disposal request in one database transaction. The same rule applies when a repair worker records a not-repairable result. A failure cannot leave a reviewed repair action without its required disposal review.

## Disposal

Staff with `assets.request_disposal` may recommend disposal but cannot approve or finalize it. `assets.approve_disposal` permits approval, rejection, return for information, or redirection to repair. Approval moves the asset only to `APPROVED_FOR_DISPOSAL`. A separate `assets.mark_disposed` action records the physical disposal date, responsible authenticated user, remarks, optional evidence, and finally moves it to `DISPOSED`.

Only one action request may be active for an asset. Opening a disposal review supersedes any conflicting pending repair review without deleting it. Replaying an already-processed request returns a friendly stale-review validation message. A data migration repairs legacy `FOR_DISPOSAL_REVIEW` assets by superseding conflicting repair rows and creating the missing disposal review from the prior not-repairable decision. It also adds a lifecycle event explaining the repair.

Review outcomes are:

- Repair review: approve repair, mark not repairable, return for information, or reject.
- Disposal review: approve disposal, send for repair instead, return for information, or reject.
- Physical disposal remains a separate final action after approval.

## Notifications and permissions

Repair requests notify all users with `assets.review_repair`; disposal requests notify all users with `assets.approve_disposal`. Repair assignment updates notify added and removed personnel. Defaults grant review capabilities to FMO Head, Campus Director, and System Administrator. Decisions notify the original requester once per event/request. No user IDs are hard-coded.

Core capabilities are `assets.view`, `assets.view_all`, `assets.manage`, `assets.inspect`, `assets.maintain`, `assets.assign_maintenance`, `assets.request_repair`, `assets.review_repair`, `assets.request_disposal`, `assets.approve_disposal`, `assets.mark_disposed`, `assets.generate_qr`, and `asset_types.manage`.

## Lifecycle

```text
ACTIVE
   ↓ inspection issue / repair request
NEEDS_REPAIR (condition)
   ↓ repair approved
UNDER_REPAIR
   ├── repaired/serviceable → ACTIVE
   └── not repairable
          ↓
FOR_DISPOSAL_REVIEW
          ↓ disposal approved
APPROVED_FOR_DISPOSAL
          ↓ physical disposal recorded
DISPOSED
```
