# Personnel and requester profiles

## Separate records

`users` holds authentication, account status, roles, and permissions. Institutional user type (`student`, `faculty`, or `staff`) describes an affiliation and never grants authority. Each approved user may maintain one requester profile for institutional ID, organizational affiliation, program, year level, and contact details. Organizational office is an affiliation only; Phase 3 does not model physical locations.

An FMO personnel profile is separate and optional. It links one approved user to FMO operational information: personnel identifier, designation, employment type, start date, contact number, personnel status, and internal notes. Creating a personnel profile does not change any role. An authorized administrator must assign the `FMO Staff` role separately when appropriate.

## FMO Staff workflow

Open **FMO Staff** and use **Add FMO Staff** to select an existing approved user who has no personnel profile. Add descriptive personnel data and skills. Designation is descriptive only; it has no authorization effect. Employment types are Job Order, Regular, Contractual, Casual, and Other.

Personnel status is independent from account status. Active personnel with an approved account are assignable. Inactive, On Leave, and Unavailable personnel are not assignable. Deactivation sets personnel status to Inactive and preserves the record. Reactivation changes only personnel status and cannot reactivate a suspended or deactivated account.

Hard deletion removes only an unused FMO personnel profile; it never deletes the linked user. It is available only to users with `personnel.delete`. The deletion guard is deliberately isolated so later work-order references can block deletion before any historical data is removed.

## Skills

Skills are managed reference data, seeded with generic examples and editable through **Skills**. Personnel may have multiple skills. One selected assigned skill may be primary; a primary skill cannot be selected unless it is in the assigned skill list. Referenced skills cannot be deleted and should be deactivated instead.

## Permissions and API

Phase 3 adds `personnel.view`, `personnel.create`, `personnel.update`, `personnel.manage_status`, `personnel.delete`, `skills.view`, `skills.create`, `skills.update`, `skills.delete`, `profiles.view_own`, `profiles.update_own`, `profiles.view_others`, and `profiles.update_others`.

FMO Head has routine personnel and skill management rights. Campus Director and Director for Instruction can view personnel. FMO Dispatcher can view personnel and skills. Requester and FMO Staff can manage their own requester profile; FMO personnel fields remain administrative. The FMO Head can delegate listed personnel and skills permissions through the existing direct-permission system.

Authenticated API endpoints are `GET/PATCH /api/v1/me/profile`, `GET /api/v1/personnel`, `GET /api/v1/personnel/{id}`, and `GET /api/v1/skills`. Personnel responses expose assignment-safe fields only and omit email, notes, credentials, and authorization data.
