# Mini ERP project rules

## UI and design system

- Before creating or changing any user interface, read [`docs/design/system-design.md`](docs/design/system-design.md) and use the shared tokens in [`wp-content/plugins/mini-erp/assets/design-tokens.css`](wp-content/plugins/mini-erp/assets/design-tokens.css).
- Treat `design-tokens.css` as the single source of truth for color, typography, spacing, borders, radii, focus, and layout dimensions. Use its `--erp-*` variables in component styles. Do not add one-off colors, font stacks, spacing scales, radii, or shadows; add and document a token first when the system needs a new value.
- Keep UI styles in the plugin asset stylesheets. Do not add inline style attributes for visual design.
- Every new or changed screen must remain usable at 320px, 390px, 768px, 1024px, and 1440px widths. Use responsive layouts and readable mobile rows/cards instead of forcing a wide desktop table onto a phone.
- Preserve semantic headings, labels, keyboard operation, visible focus, reduced-motion preferences, and meaningful empty/error/loading states.
- Preserve route authorization, capabilities, nonces, form field names, data attributes used by scripts, business rules, audit logging, and data scopes when changing markup or styles. A visual change must not weaken access checks or expose data outside the current user's scope.
- Before finishing UI work, review the rendered screen at desktop and mobile sizes against the system design and the approved references in `docs/design/references/`. Record any runtime or browser limitation clearly.

