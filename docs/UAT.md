# Version 1 user acceptance testing plan

Use a disposable, non-production database and fictitious accounts/files. A human tester must complete **Actual**, **Result**, **Tester**, **Date**, and **Remarks** for every case. `Not run` is not a pass. Capture a defect reference and retest after a fix. Test both desktop and narrow/mobile web widths where the web UI is involved.

Preconditions: seed production-safe roles/permissions, create a System Administrator interactively, create test campus/building/location/category and approved test accounts. Configure an Android test build against a reachable HTTPS test server for non-local devices. Record device/OS, browser, Laravel/PHP/MySQL versions, and test environment in Remarks. Never use real student or staff records.

| ID | Role | Scenario and steps | Expected result | Actual | Result | Tester | Date | Remarks |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| R01 | Requester | Register; attempt to supply `APPROVED`, administrator role and permission fields | Account stays pending with no privileged role/permission |  | Not run |  |  |  |
| R02 | Requester | Try protected web/API routes before approval; sign in after approval | Pending access denied; approved access succeeds |  | Not run |  |  |  |
| R03 | Requester | Update own profile; submit request with campus/building/location, category, preference and photo | Valid request and private attachment recorded; preference is not assignment |  | Not run |  |  |  |
| R04 | Requester | Change Work Order ID or attachment ID to another requester's | Other request and file are denied |  | Not run |  |  |  |
| R05 | Requester | Receive information request; edit/resubmit; view approval/disapproval, assignment, progress and completion | Correct state/history/notification without internal notes |  | Not run |  |  |  |
| R06 | Requester | Filter own history; export CSV; print own Work Order | Only own records; no private staff notes |  | Not run |  |  |  |
| H01 | FMO Head | Approve registration; create/activate staff, skills and locations | Audit and permission boundaries preserved |  | Not run |  |  |  |
| H02 | FMO Head | Screen, request clarification, approve/disapprove and assign a request | Correct allowed transitions and audit history |  | Not run |  |  |  |
| H03 | FMO Head | Resolve assessment exception, monitor active session, return/verify completion | Only authorized action accepted; actor/time recorded |  | Not run |  |  |  |
| H04 | FMO Head | Filter dashboard/history, CSV and print record | Counts and report scope match source Work Orders |  | Not run |  |  |  |
| D01 | Dispatcher | Assign one and then two eligible staff; remove/reassign with reason | Current assignments and history accurate; duplicates rejected |  | Not run |  |  |  |
| D02 | Dispatcher | Review skills/workload and assessment queue; try final approval/verification | Operational view works; unauthorized final decisions denied |  | Not run |  |  |  |
| S01 | FMO Staff web | Open own assigned and another staff member's unrelated Work Order/API detail | Own assignment works; unrelated record denied |  | Not run |  |  |  |
| S02 | FMO Staff web | Acknowledge/assess, attach evidence, Start Work, add update/photo, end for continuation/wait, complete | Individual actions and evidence attributed correctly; invalid transition blocked |  | Not run |  |  |  |
| S03 | FMO Staff web | Attempt to end another person's session or continue after removal | Denied; own historical contribution remains visible |  | Not run |  |  |  |
| M01 | FMO Staff mobile online | Sign in, sync assigned tasks, assess, Start Work, update, upload, end, submit completion | Server accepts once; unrelated tasks absent |  | Not run |  |  |  |
| M02 | FMO Staff mobile offline | Sync online; disconnect; assess, capture photo, Start Work, update/photo, end for continuation; kill/restart app | All pending text and media remain locally after restart |  | Not run |  |  |  |
| M03 | FMO Staff mobile offline | Reconnect and sync M02; repeat sync after response loss/timeout | Each operation/media exists once on server; local items reconcile |  | Not run |  |  |  |
| M04 | FMO Staff mobile | Remove assignment or suspend account while offline; then sync | Server rejects stale write; local content remains recoverable and is not silently deleted |  | Not run |  |  |  |
| M05 | Shared test device | Staff A syncs, resolves pending work, logs out; Staff B logs in | Staff B sees none of A's tasks, files, queue or notifications |  | Not run |  |  |  |
| T01 | Two FMO Staff | Both assess/work on shared Work Order with separate sessions/updates | Both see shared task; each owns their actions; unrelated tasks remain hidden |  | Not run |  |  |  |
| C01 | Campus Director | Create direct Work Order and inspect decision/audit; requester attempts same API | Director path works; requester is denied; no auto-assignment |  | Not run |  |  |  |
| I01 | Director for Instruction | View oversight dashboard/reports; attempt approval/assignment | Read access works; mutation denied by default |  | Not run |  |  |  |
| O01 | FMO Oversight | View authorized history; attempt workflow modification | Read-only default enforced |  | Not run |  |  |  |
| A01 | System Administrator | Assign permitted role; suspend account with active web/API session | Role change audited by administration policy; suspended access stops |  | Not run |  |  |  |
| X01 | All relevant roles | Check login, forms, queues, dashboard, reports at desktop and narrow widths with keyboard | Labels, focus, validation and empty states remain usable |  | Not run |  |  |  |

Exit criteria: all critical/high defects resolved and retested; requester/staff isolation and mobile persistence/account-switch scenarios pass; an authorized campus representative signs off. Formal UAT has **not** been executed by the developer during Phase 12 preparation.
