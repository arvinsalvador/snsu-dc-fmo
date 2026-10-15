# Asset Repair and Disposal

Asset action requests preserve who requested review, the originating inspection when applicable, problem description, recommendation, evidence, reviewer, decision, reason, and timestamps. Lifecycle events separately preserve every transition.

## Repair

Staff with `assets.request_repair` may submit a repair review from an inspection or asset page. Users with `assets.review_repair` may mark it **Repairable — Approve for Repair**, **Not Repairable**, return it for more information, or reject it. Approval moves the asset to `UNDER_REPAIR`. Repair personnel with `assets.maintain` record work performed, textual parts/materials, result, remarks, and required photo evidence.

- Repaired/serviceable → condition `GOOD`, lifecycle `ACTIVE`.
- Partially repaired → condition `NEEDS_REPAIR`, lifecycle remains `UNDER_REPAIR`.
- Not repairable → condition `UNUSABLE`, disposal review is opened.

## Disposal

Staff with `assets.request_disposal` may recommend disposal but cannot approve or finalize it. `assets.approve_disposal` permits approval, rejection, return for information, or redirection to repair. Approval moves the asset only to `APPROVED_FOR_DISPOSAL`. A separate `assets.mark_disposed` action records the physical disposal date, responsible authenticated user, remarks, optional evidence, and finally moves it to `DISPOSED`.

## Notifications and permissions

Repair requests notify all users with `assets.review_repair`; disposal requests notify all users with `assets.approve_disposal`. Defaults grant those review capabilities to FMO Head, Campus Director, and System Administrator. Decisions notify the original requester once per event/request. No user IDs are hard-coded.

Core capabilities are `assets.view`, `assets.view_all`, `assets.manage`, `assets.inspect`, `assets.maintain`, `assets.request_repair`, `assets.review_repair`, `assets.request_disposal`, `assets.approve_disposal`, `assets.mark_disposed`, `assets.generate_qr`, and `asset_types.manage`.

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
