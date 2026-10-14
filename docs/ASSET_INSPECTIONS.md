# Asset Inspections

## Inspectable asset registry

`InspectableAsset` represents one identifiable item such as `FE-DC-001`, never a bulk quantity. It belongs to a database-backed `AssetType` and reuses the existing campus, building, floor, and office/room/area hierarchy with optional exact placement. Registry states are `ACTIVE`, `OUT_OF_SERVICE`, `RETIRED`, and `MISSING`.

The initial seeded types are Fire Extinguisher, Emergency Light, Air Conditioner, Wall Fan, and Ceiling Fan. Asset types store configurable checklist entries and valid result options; checklists are not hard-coded in Blade.

This is intentionally a facilities inspection registry, not an inventory ERP. Purchasing, depreciation, suppliers, warehousing, valuation, and stock movement are outside this phase.

## Inspection runs and records

An inspection-type routine template selects one asset type and a location scope. When its occurrence is generated, the server creates an `InspectionRun` and snapshots every matching active asset into `InspectionItem` rows. Later registry changes cannot silently rewrite that run.

Each item requires every checklist response, a high-level result, inspector identity, and inspection time. Evidence follows the template rule. Supported generic results include `GOOD`, `NEEDS_ATTENTION`, `DEFECTIVE`, `MISSING`, and `OUT_OF_SERVICE`; asset types can add suitable options such as `NEEDS_REFILL` and `EXPIRED`.

A run moves from open to in progress and completes only when every snapshotted item has an inspection record. Progress and attention counts are calculated from item records. Asset detail retains historical inspections even after an item is retired or placed out of service.

## Digital inspection tag readiness

The stored period, asset ULID/code, item checks, result, inspector, timestamp, summary, and evidence can support a future physical-tag-like digital view or QR lookup. No final tag layout or public scan endpoint is included until the physical tag specification and disclosure rules are supplied.
