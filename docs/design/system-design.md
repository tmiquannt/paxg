# Mini ERP system design

## Product direction

Mini ERP is an operational workspace for sales, purchasing, warehouse, finance, and account administration. The interface should feel calm, precise, and trustworthy. Favor clear hierarchy, readable data, direct actions, and restrained color over decoration.

The approved visual direction is **Framed Sheet**: a pale blue-gray workspace, a white bordered work surface, thin separators, dark ink, and a restrained teal accent. Avoid gradients, decorative metric tiles, resting shadows, arbitrary icon blocks, and zebra striping. Use icons only when they improve recognition and remain paired with clear labels.

Approved references:

- [Orders list, desktop and mobile](references/orders-list-approved.png)
- [ERP users and role assignment, desktop and mobile](references/users-roles-approved.png)
- Existing order lifecycle and workflow constraints: [ERP orders interface](erp-orders-interface.md)

## Source of truth

`wp-content/plugins/mini-erp/assets/design-tokens.css` owns the shared palette, type, spacing, borders, focus treatment, and shell dimensions. `frontend.css` and `admin.css` consume those tokens. If a component needs a new design value, add a named token there and document its purpose here; do not introduce local magic values or inline visual styles.

The token file is enqueued before both stylesheets in the ERP app and the WordPress bootstrap screen. The brand mark size, login panel width, and stacked-table label width are also shared tokens. Keep its cache version tied to the file modification time.

## Application frame

- Use the branded top bar for the product name, current page context, signed-in user, and sign-out action.
- Keep the desktop navigation in a 228px rail with short, labeled groups. Only render routes authorized for the current actor; grouping must never grant access.
- On narrow screens, collapse navigation behind a native, keyboard-operable disclosure. Keep the current page visible in its summary.
- Put page content on a white, bordered sheet over the pale workspace canvas. Use a 16px sheet radius and no resting drop shadow.
- Keep the content width comfortable for the page type. Let operational tables and forms use available space while preserving readable line length for prose.

## Components and data

- Page headings state the current task. Put the primary next action near the heading or the relevant data.
- Tables use a quiet header, thin row separators, left-aligned descriptions, and right-aligned numeric and currency columns with tabular figures. Do not use zebra striping.
- On phones, convert short operational lists into labeled cards or stacked rows. For intrinsically wide detail tables, provide a clearly bounded horizontal scroll region.
- Keep each short-list table in an `.erp-table-scroll.erp-table-stack` wrapper. The responsive table script applies visible column labels, and rows become cards on phones while retaining the table headers for assistive technology.
- Keep wide order, invoice, audit, report, and reconciliation tables in `.erp-table-scroll` regions. Make these regions keyboard-focusable so users can reach columns beyond the viewport without scrolling the whole page.
- Inputs share tokenized height, border, radius, and focus treatment. Labels stay visible; help text explains consequential choices.
- Use status color only for status, with a text label always present: teal/blue for in-progress or confirmed, green for completed, amber for attention, red for failed or cancelled, and neutral gray for draft/closed states.
- Empty, saved, permission, and validation messages must be explicit and kept close to the relevant workflow.
- Use the existing fixed ERP roles. Do not present arbitrary capability toggles as role configuration. Warehouse and cash-account visibility follows the existing assignment policy; users see only assigned cash funds/accounts.

## Responsive behavior

Use these review widths: 320px and 390px for phones, 768px for compact/tablet layouts, 1024px for small desktop, and 1440px for wide desktop.

- At 760px and below, the shell becomes one column, the navigation becomes a disclosure, page padding tightens, and multi-column workspaces become a single column.
- At 640px and below, list rows stack their key, status, and secondary metadata; actions remain reachable without horizontal page scrolling.
- Forms use full-width controls on phones. Keep labels, values, and primary actions readable at 200% zoom.
- Respect `prefers-reduced-motion`; do not rely on animation to convey state.

## Accessibility and behavior boundaries

- Use native links, buttons, forms, selects, and disclosures. Maintain keyboard access and visible focus.
- Every control must have a programmatic label. Status must not depend on color alone.
- Preserve WordPress/ERP authorization checks, route behavior, nonces, form names, script selectors, audit trails, and business state transitions when changing presentation.
- Never add sample business facts, fake metrics, or fictional records to a live ERP page. Approved design images are visual references only.

## WordPress administration surfaces

- Keep the ERP UI Studio as a capability-protected WordPress admin page so the authorized ERP System Admin can open it from `wp-admin`.
- The Studio may change the application name, plain-text dashboard guidance, navigation labels and order, and visibility of supported dashboard cards. All displayed dashboard data must still pass the actor's existing capabilities and record-scope checks.
- The Studio must not edit role definitions, capabilities, warehouse or cash-account assignments, required fields, business state transitions, accounting rules, or HTML/CSS/JavaScript. Continue to use the fixed role set and the source-of-truth design tokens above.
- Keep user and role assignment in the ERP access screen. The single ERP System Admin can continue to configure other non-WordPress-Administrator accounts, including role, enabled state, and assigned warehouse/cash-account scopes.
- Style WordPress login, lost-password, and reset-password pages with the same token palette and compact framed surface. Keep WordPress authentication, recovery links, nonce checks, and error states intact.

## Review gate

Before a UI change is considered complete, inspect the actual rendered UI on a desktop and a phone-sized viewport, compare it with the approved references, and confirm there is no page-level horizontal overflow. Check the main route, empty state, validation or saved state, and the most important role-specific view when runtime access is available. Source-level checks do not substitute for a browser review; state any unavailable review clearly.

