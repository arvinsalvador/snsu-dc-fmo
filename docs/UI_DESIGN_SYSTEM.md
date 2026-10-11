# SNSU FMO Web UI Design System

This is the implementation reference for the Laravel Blade application in `web/`. It covers presentation only; authorization, workflow policy, and domain behavior remain in gates, policies, controllers, and services.

## Application shell and navigation

Authenticated pages use a persistent desktop sidebar and compact top header. The permission-aware sidebar becomes an off-canvas drawer on narrow screens, with a backdrop, labelled close control, synchronized `aria-expanded` state, and Escape-key support. The top bar supplies page context, notifications, account identity, and sign-out. Identity uses `x-avatar`, with deterministic initials when no image exists.

Navigation labels describe user intent:

- **My Requests** contains Work Orders submitted by the current account.
- **My Tasks** contains Work Orders assigned to the current FMO personnel profile.
- **All Work Orders** and operational queues appear only when authorized.
- **Active Work** and **For Verification** retain distinct active states even though they share an execution queue route.

## Visual foundation

Use the existing Instrument Sans and Tailwind CSS 4 stack. Primary blue is reserved for navigation and primary action; slate neutrals provide structure. Semantic colors are stable: blue for new or informational, amber for attention or waiting, indigo for active execution, emerald for verified or successful, rose for rejected or destructive, and slate for inactive or neutral. Meaning always has a text label and never relies on color alone.

Spacing follows Tailwind's four-pixel scale. Cards use modest 12px rounding, a light slate border, and restrained shadow. Avoid gradients, oversized display type, mixed icon styles, and decorative effects that compete with operational information. Desktop queues and tables may use the full content area; reading, authentication, and data-entry surfaces use a narrower measure.

## Reusable Blade components

- `x-page-header`: title, optional description, and action slot.
- `x-stat-card`: role-aware dashboard metric with optional destination and icon.
- `x-status-badge`: centralized workflow, personnel, registration, and account status label.
- `x-empty-state`: meaningful empty-list presentation with optional description and action.
- `x-icon`: the single outline SVG icon family. Icons inherit current color and decorative icons are hidden from assistive technology.
- `x-avatar`: consistent person identity using initials and size variants.
- `x-alert`: semantic information, success, warning, and error feedback with suitable live-region roles.
- `x-breadcrumbs`: compact hierarchy for deep operational and edit screens.
- `x-confirm-dialog`: accessible confirmation for forms carrying `data-confirm`.
- `x-error-page`: branded 403, 404, 419, and 500 responses without implementation details.
- `x-photo-picker`, `x-evidence-gallery`, and `x-image-lightbox`: evidence capture, browsing, and full-image viewing.

Shared CSS component classes include `btn`, `btn-primary`, `btn-secondary`, `btn-success`, `btn-warning`, `btn-danger`, `btn-ghost`, `icon-btn`, `text-link`, `section-card`, `table-wrap`, `filter-bar`, `form-section`, `definition-grid`, `permission-group`, `permission-option`, `queue-card`, and `timeline-item`.

## Page hierarchy

Page structure follows: page header, optional summary or filter, primary content, and supporting content. Titles are short and action-oriented; supporting copy explains scope or the next decision. Deep screens add breadcrumbs. Empty lists explain why they are empty and offer a relevant next step when one exists.

## Forms and actions

Labels sit above controls; required fields use a red asterisk. Inputs have a visible keyboard focus ring and invalid controls retain a visible error state. Validation errors appear in a page-level accessible alert. Long forms are divided into `form-section` cards. Submit buttons provide a short in-progress state to reduce accidental duplicate submissions.

Buttons have a consistent minimum height and semantic hierarchy: primary for the main next action, secondary for supporting actions, success for completion or confirmation, warning for workflow redirection, and danger only for destructive actions. Use `icon-btn` only when a nearby label or `aria-label` makes the action unambiguous. Do not use emoji, text glyphs, or mixed icon libraries.

Consequential forms carry a concise `data-confirm` message and use `x-confirm-dialog`; routine saves do not need confirmation. Checkbox and radio inputs use visible text cards for workflow outcomes or personnel selection. Help copy belongs in `.help-text`. Password screens provide a stateful show/hide control.

## Tables, cards, and queues

Use `table-wrap` for safe horizontal scrolling and row separation. Headers are subdued, rows have a light hover state, and one visible View action is preferred over dense action groups. Operational tables may opt into the responsive card treatment: each cell supplies a `data-label`, headers become visually hidden on narrow screens, and rows become independent cards.

Queues use `queue-card` when a table would hide the next action. Put Work Order number, subject, location, requester, status, age, and primary action in a predictable order. Pair all empty lists with `x-empty-state`.

## Work Order experience

The request form is a numbered sequence of focused `form-section` cards. Location fields preserve Campus → Building → Floor → Area dependency and explain optional levels. Preferred personnel is explicitly a preference and never an assignment.

The detail surface uses lifecycle-first hierarchy: compact header, prominent current-status and next-action panel, request overview, current work, grouped evidence, completion, and activity timeline. Location, requester, preferred personnel, active assignees, and compact metadata live in a sticky one-third sidebar on wide screens. The layout stacks on smaller screens.

Assignment uses selectable personnel cards with identity, designation, skills, availability, and workload context. Execution separates updates, session outcomes, completion submission, active sessions, and administrative recovery. Assessment and verification choices include plain-language consequences before action buttons.

Evidence photos render from protected thumbnail routes in responsive 4:3 tiles with lazy loading. Full images load only after opening `x-image-lightbox`. The viewer supports close, previous/next, image counter, Escape and arrow keys, and horizontal swipe. Group request, assessment, work, and completion evidence separately. More than eight images start collapsed behind a View all control.

## Management, reports, and notifications

Personnel, users, registrations, roles, locations, categories, and skills share the same header, filter, table or card, empty-state, and confirmation language. Role permissions are grouped by module. Account and personnel states use status badges. Destructive actions identify the record and consequence.

Dashboard metrics and shortcuts are role-aware: requesters see request progress, assigned personnel see field queues, and management sees operational control totals. Reports prioritize filters, result totals, readable dates, and print or export actions. Notifications distinguish unread items with styling and text context and expose one clear destination.

Dates shown to users use the established Asia/Manila context and readable `M j, Y · g:i A` format where time matters.

## Responsive and accessible behavior

- Preserve a logical heading hierarchy and meaningful page titles.
- Every field has a visible label; every icon-only control has an accessible name.
- Keyboard focus is visible on links, controls, summary elements, dialogs, drawers, and the lightbox.
- Success and informational alerts use `role=status`; urgent validation and error alerts use `role=alert`.
- Status and validation meaning is conveyed with text as well as color.
- Images have contextual alternative text; decorative SVGs are ignored by assistive technology.
- Interactive controls use at least 44px touch targets where practical.
- Forms collapse to one column before labels or controls become cramped.
- No page creates document-level overflow at 390px; only an intentional `.table-wrap` may scroll horizontally.

## Verification checklist

Before merging UI work:

1. Run `artisan view:clear` and `artisan view:cache`.
2. Run route and feature smoke tests for public, requester, assigned personnel, management, and administrator roles.
3. Run the complete PHPUnit suite.
4. Run `npm run build` and treat asset compilation failures as release blockers.
5. Inspect representative pages at desktop, tablet, and 390px mobile widths for clipping and document-level overflow.
6. Check console errors, drawer and dialog keyboard behavior, protected evidence loading, empty states, validation errors, and safe forbidden or not-found pages.

Keep Blade directives, component slots, and permission checks on separate lines with explicit closing directives. `artisan view:cache` compiles templates, but HTTP render tests are also required to catch malformed directive nesting and runtime data errors.
