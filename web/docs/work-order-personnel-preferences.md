# Work Order Personnel Preferences

The requester preference flow is:

```text
Work Type
  -> Relevant Skills
  -> Matching active FMO Personnel
  -> Requester Preferences
  -> FMO Assignment
```

Authorized category managers configure one or more **Relevant Personnel Skills** for each Work Type. The request form then lists only active, unarchived, approved personnel who have at least one of those assigned skills. Every assigned skill participates in matching; the primary skill is displayed first but is not required to be the matching skill.

The safe lookup endpoint reports one of three successful states: `ready`, `no_matches`, or `unmapped`. Request failures are shown separately, so a permission, server, or network error cannot be mistaken for a valid empty result.

A requester may choose zero to `WORK_ORDER_MAX_PREFERRED_PERSONNEL` people (three by default). The searchable selector explains matching skills and removes selections that become invalid when the Work Type changes. An unmapped Work Type or a Work Type with no matching personnel never blocks submission.

Requester preference is never an assignment. Preferences do not create assignment records, grant access, affect My Tasks or workload counts, or send assignment notifications. The FMO Head or Dispatcher remains free to assign preferred personnel, different eligible personnel, or any permitted team through the existing assignment workflow.

Preferences are stored in `work_order_personnel_preferences`. Category mappings are stored in `work_order_category_skill`. The migration backfills every existing `work_orders.preferred_fmo_personnel_id` value before removing that legacy column, so the pivot is the only ongoing source of truth.

Standard category mappings are populated by the normal seeders and by an idempotent repair migration for databases that already existed when the relationship was introduced. The repair inserts missing standard mappings only; it does not delete or replace administrator-configured mappings.
