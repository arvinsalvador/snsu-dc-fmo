# SNSU FMO UI Design System

## Application shell

Authenticated pages use a persistent desktop sidebar and a compact top header. Navigation is permission-aware and becomes an off-canvas drawer on narrow screens. The content area uses a wide operational layout while longer forms and reading surfaces remain constrained by their own grid.

## Visual foundation

The interface uses the existing Instrument Sans and Tailwind CSS 4 stack. The primary administrative color is a restrained university-aligned blue, with slate neutrals for structure. Semantic colors are reserved for status: blue for open information, amber for attention, indigo for active workflow, emerald for successful states, rose for destructive or rejected states, and slate for neutral states.

Spacing follows Tailwind's four-pixel scale. Cards use modest 12px rounding, a light slate border, and a subtle shadow. Avoid large shadows, gradients, or decorative effects.

## Reusable Blade components

- `x-page-header`: title, optional description, and optional action slot.
- `x-stat-card`: dashboard metric with optional destination.
- `x-status-badge`: centralized workflow and account status label. Always includes text, not color alone.
- `x-empty-state`: meaningful empty-list presentation with optional description and action slot.

Shared CSS component classes include `btn`, `btn-primary`, `btn-secondary`, `btn-success`, `btn-danger`, `btn-ghost`, `section-card`, `table-wrap`, `filter-bar`, `form-section`, and `timeline-item`.

Keep Blade directives, component slots, and permission checks on separate lines with explicit closing directives. `artisan view:cache` compiles templates, but PHP syntax checking of the generated views or an HTTP render test is also needed to catch malformed directive nesting.

## Forms and actions

Labels sit above controls; required fields use a red asterisk. Inputs have a clear keyboard focus ring and validation errors appear in a page-level accessible alert. Long forms are divided into `form-section` cards. Submit buttons provide a short in-progress state to reduce accidental duplicate submissions.

Buttons have a consistent minimum height and semantic hierarchy: primary for the main next action, secondary for supporting actions, success for completion/confirmation, and danger only for destructive actions.

## Tables and lists

Use `table-wrap` for safe horizontal scrolling on small screens. Headers are subdued, rows have a light hover state, and a single visible View action is preferred over dense action groups. Pair empty lists with `x-empty-state`.

## Status badges and dates

Use `x-status-badge` instead of scattered enum formatting and color classes. Dates shown to users use the established Asia/Manila context and readable `M j, Y · g:i A` style where time matters.

## Alerts, timelines, and responsive behavior

Success and error alerts use `role=status` and `role=alert` respectively. Workflow history uses the `timeline-item` vertical pattern. The sidebar drawer is toggled from the mobile header and includes a backdrop. Forms use responsive grids; tables remain horizontally scrollable rather than overflowing the viewport.
