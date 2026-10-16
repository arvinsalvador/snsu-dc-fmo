# Assets and Equipment

The asset registry represents individual, physically identifiable facilities equipment. Every asset uses a human-readable unique code such as `FE-DC-001` in addition to its internal ULID.

## Asset types

Authorized users manage asset types under **Assets / Equipment → Manage types**. A type has a name, short code/prefix, description, default inspection frequency, active state, and reusable line-based checklist. Deactivation is non-destructive: existing assets and their histories retain the type, while new assets may select only active types.

## Location

Asset location follows Campus → Building → optional Floor → optional Office/Room/Area. The form loads children only after their parent is selected and resets invalid descendants whenever a parent changes. The server independently verifies every relationship and active state. `exact_placement` records instructions such as “Beside the main entrance door.”

## Identity and photos

Assets store code, type, name/description, brand, model, serial number, installed/acquired date, frequency, notes, and exact location. Registration and editing accept both an **Asset Photo** and **Placement / Location Photo**. Photos use the shared private evidence pipeline: orientation correction, bounded resizing without upscaling, configured compression, thumbnails, protected inline delivery, and the common lightbox.

## Condition and lifecycle

Physical condition and operational lifecycle are deliberately separate:

- **Condition** describes the equipment's physical state: `PRISTINE`, `GOOD`, `FAIR`, `NEEDS_REPAIR`, `DAMAGED`, `BROKEN`, or `UNUSABLE`.
- **Lifecycle** describes its operational disposition:
  - `ACTIVE`: available for normal operations and recurring inspection.
  - `UNDER_REPAIR`: repair was approved and work is underway.
  - `FOR_DISPOSAL_REVIEW`: disposal was recommended and awaits a decision.
  - `APPROVED_FOR_DISPOSAL`: disposal was approved, but physical disposal has not yet been recorded.
  - `DISPOSED`: authorized physical disposal was recorded.
  - `RETIRED`: administratively withdrawn without using the disposal workflow.

For example, an asset may be `NEEDS_REPAIR` while still `ACTIVE` before repair approval, or `UNUSABLE` while `FOR_DISPOSAL_REVIEW`. The registry and detail screens use distinct badge families and help text so these concepts are not presented as synonyms.

Repair/disposal requests are separate auditable records. Asset lifecycle cannot be changed from the asset edit form or by supplying a manipulated lifecycle value. The lifecycle service permits only the workflow transitions below and rejects invalid direct changes:

```text
ACTIVE → UNDER_REPAIR → ACTIVE
ACTIVE → FOR_DISPOSAL_REVIEW
UNDER_REPAIR → FOR_DISPOSAL_REVIEW
FOR_DISPOSAL_REVIEW → ACTIVE
FOR_DISPOSAL_REVIEW → UNDER_REPAIR
FOR_DISPOSAL_REVIEW → APPROVED_FOR_DISPOSAL → DISPOSED
ACTIVE → RETIRED
```

Repair/disposal reviewers perform approval transitions through their dedicated permissions; only `assets.mark_disposed` can record the final physical disposal. Ordinary staff report condition and request review rather than choosing lifecycle values. Disposed and retired assets remain searchable and historical but are excluded from new recurring inspection runs.
