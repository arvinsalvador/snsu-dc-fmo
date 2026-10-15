# Asset Inspections and QR

## QR identity

Every asset receives a stable random 48-character token. Its QR contains only the authenticated `/assets/scan/{token}` URL—never staff identifiers, asset JSON, history, or secrets. The token locates a record but grants no authority: normal login, account approval, and `assets.view` checks still apply. Authorized management can print a compact label or regenerate a compromised token; regeneration invalidates the previous label and creates a lifecycle audit event.

The scan screen shows code, type, location, current condition, lifecycle, and last inspection. Staff may inspect the asset, open its record, or report a problem. If the asset belongs to a currently accessible open recurring inspection run, scanning resolves to that exact run item. A supplied run that does not contain the asset displays a warning and records nothing. Asset-code search is the no-camera fallback; no large browser scanner dependency is required.

## Inspection records

`InspectionItem` is the permanent exact-asset record for both recurring-run and standalone QR inspections. It stores inspector personnel/user, timestamp, condition, checklist results, summary, remarks, recommendation, and private attachments. Every submission creates history rather than overwriting an earlier inspection.

Condition choices use staff-facing explanations for pristine, good, needs attention, needs repair, broken, and disposal recommendation. Each checklist comes from the selected Asset Type rather than a hard-coded Blade form. Asset and optional placement photos use camera capture or file choice. Evidence is mandatory for repair, damaged/broken/unusable, and disposal recommendations.

Completing an inspection updates `last_inspected_at` and current condition. For a recurring run it also updates item progress; the occurrence completes only after every snapshotted asset item is inspected.

## Future mobile work

Flutter remains deferred. A future client should cache authorized assets, resolve opaque QR tokens, stage checklist/evidence mutations offline, upload media through the existing private pipeline, and surface sync conflicts without weakening server authorization.
