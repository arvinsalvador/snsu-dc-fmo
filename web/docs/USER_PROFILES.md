# User Profiles, Profile Pictures and Passwords

Every approved, signed-in user has **My Profile** (`/profile`), reachable from the account menu in the header (My Profile, Change Password, Logout) and from the sidebar.

## What users can edit

| Section | Fields | Stored in |
|---|---|---|
| Personal information | Full name and contact number (the email address is shown read-only) | `users.name`, `users.email`; contact number on the FMO Personnel record when the user has one, otherwise the requester profile |
| Institutional information (existing, permission `profiles.update_own`) | Institutional ID, department, college, program, year level, office | `requester_profiles` |
| Profile photo | JPG, PNG or WebP, up to 3 MB | `users.avatar_path`, `users.avatar_updated_at` |
| Password | Current password, new password, confirmation | `users.password` (hashed) |

The name is trimmed and its spacing normalised; it cannot be blank. The profile endpoints read only the fields listed above, never the whole request, so submitting `role`, `roles`, `permissions`, `status`, `is_admin`, `user_type` or an `id` has no effect.

## Read-only (system-managed)

Institutional type, roles and permissions, account status, FMO Personnel status, designation, employment type, skills and primary skill. They are shown on the profile page for information only and change only through their dedicated administration workflows. Changing a profile never changes a user's approval status: unapproved accounts cannot use the application, including the profile pages.

User names are stored once, on the user. FMO Personnel records reference the user, so there is no duplicated name to keep in sync.

## Email

Email is required and unique:

- `users.email` has a database **unique index** (collation is case-insensitive), so duplicates cannot be created even by concurrent requests; a violation is reported as a normal validation error.
- Registration, sign-in, the administrator edit all trim and lowercase the email before validating. `Arvin@Example.Test` and `arvin@example.test` are the same account.
- **Users cannot change their own email.** It is shown read-only on My Profile; the profile page and `PATCH /api/v1/profile` never read an `email` field, so a submitted value is ignored. Only an administrator with `profiles.update_others` can change another user's email, under the same validation: required (blank, null or missing is rejected) and unique excluding that user's own row. Each change is audited.
- Duplicate message: *An account with this email address already exists.*
- The application does not use email verification, so none is added when an email changes.

## Profile pictures

- Uploads are validated by content (not file name or client type), limited to 3 MB and to sane pixel dimensions, auto-oriented, **cropped to a centred square and resized to 320 px**, then stored on the private local disk as `avatars/{user id}/{ulid}.{ext}`. Client file names are never used.
- Replacing a photo saves the new file first, updates the user, and only then deletes the previous file. Removing a photo deletes the file and returns to initials; the account and email are untouched. Only paths under `avatars/` are ever deleted.
- Pictures are served by `GET /avatars/{user}` (and `/api/v1/avatars/{user}`) to any signed-in, approved user, so a requester's photo renders on a Work Order for anyone who may see that Work Order. The file path is never exposed. The URL carries a version (`?v=`) so a changed photo is never shown stale, and responses are private and cached for a day.
- The picture belongs to the **user**. Work Orders, transport requests, messages and other records reference the user and are never given a copy, so history stays valid when someone changes their photo. Avatars read only columns of the already-loaded user, so lists add no queries.

### Avatar components

- `<x-user-avatar :user="$user" size="sm" />` is the single component to show a person: the picture, or initials when there is none. Sizes: `xs`, `sm`, `md`, `lg`, `xl`. Images carry the alt text *Profile picture of {name}*.
- `<x-user-stack :users="..."/>` shows overlapping avatars with names for assignee lists.
- `<x-avatar :name="..."/>` is the low-level initials circle used by the above.
- Initials come from one helper, `App\Support\Initials`: first letter of the first and last name, ignoring suffixes (Jr., Sr., II, III, IV). Examples: Arvin S. Salvador → AS, Joey G. Escauso → JE, Blas C. Vicio Jr. → BV, Elmer O. Toldo → ET.

Avatars are shown in the header menu, My Profile, Work Order lists, queues and detail (requester, assigned team, assessment and session authors), Work Order messages, Transportation, Preventive Maintenance plans and tasks, registration review, personnel, roles and user management.

## Changing your own password

Requires the **current password** (Laravel's `current_password` rule), a confirmed new password under the shared policy (`App\Support\PasswordPolicy`, minimum 12 characters, the same rule as registration), and a new password different from the current one. The password is hashed by the model cast. On success the remember-me token is rotated and the user's API tokens (other than the one making an API request) are revoked, so other apps sign in again. Message: *Password updated successfully.*

## Administrator password reset

Permission `users.reset_password` (FMO Head; System Administrator holds every permission). On **Users → user → Security** the administrator enters the new password twice (or uses *Generate temporary password*, created in the browser) and their **own** password to confirm. Confirmation dialog included.

- Administrators can only **set** passwords. Nothing displays, returns, or decrypts an existing password or hash, in pages or in the API.
- Only System Administrators can reset another System Administrator; nobody uses this route on themselves (use Change Password).
- The target's API tokens and database sessions are deleted and their remember-me token is rotated, so they are signed out everywhere.
- Administrators with `profiles.update_others` can also edit another user's **name and email** (same validation, same uniqueness). Roles and permissions remain in their own workflows.

## Audit trail

`account_audit_events` records, with the actor and time: `profile_updated` (name change), `email_changed` (old and new address), `photo_changed`, `photo_removed`, `password_changed` (by the user), `password_reset` (by an administrator). Passwords and hashes are never written. Authorised administrators see the last 15 events under **Activity / Audit** on the user detail page.

## API (`/api/v1`)

```text
GET    /profile            account profile (no password or hash)
PATCH  /profile            name, contact_number (email is ignored)
POST   /profile/photo      multipart field "photo"
DELETE /profile/photo
PUT    /profile/password   current_password, password, password_confirmation
GET    /avatars/{user}     picture for any signed-in user
```

`/me/profile` (institutional fields) is unchanged. There is no administrator password-reset API.

## Security notes

Explicit field lists instead of mass assignment; password reset requires permission plus re-authentication; reset and change endpoints are rate-limited; avatars are re-encoded by GD (no uploaded bytes are served as-is) and served with `nosniff`; deleted files are limited to the user's own `avatars/` directory.
