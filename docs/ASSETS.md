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

- Condition: `PRISTINE`, `GOOD`, `FAIR`, `NEEDS_REPAIR`, `DAMAGED`, `BROKEN`, `UNUSABLE`.
- Lifecycle: `ACTIVE`, `UNDER_REPAIR`, `FOR_DISPOSAL_REVIEW`, `APPROVED_FOR_DISPOSAL`, `DISPOSED`, `RETIRED`.

Repair/disposal requests are separate auditable records. Asset lifecycle cannot be casually changed from the edit form; workflow decisions create lifecycle events. Disposed and retired assets remain searchable and historical but are excluded from new recurring inspection runs.
