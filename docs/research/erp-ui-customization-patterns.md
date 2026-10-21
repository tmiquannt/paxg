# Research: UI customization patterns in production ERP systems

**Date:** 2026-10-21  
**Purpose:** Answer how established ERP products let customers tailor the interface while keeping business behavior and access control dependable. Apply the documented patterns to Mini ERP on WordPress.  
**Evidence boundary:** This note uses first-party product and platform documentation only. It reports documented capabilities, not independent runtime testing, and it does not claim every vendor edition or deployment exposes every feature.

## Short answer

The sampled ERP product docs do not describe customization as editing arbitrary CMS pages. They describe bounded customization inside the application: people personalize their own lists and workspaces; authorized key users or administrators can change supported fields, labels, views, dashboards, and navigation; developers extend new workflows and business behavior. Roles, record scope, and server-side actions remain governed by a separate authorization model.

That is a pattern across the products below, not a claim that all ERPs implement the same architecture. **Inference:** for this WordPress ERP, the closest fit is to retain plugin-owned ERP routes, data, and business rules, then add a capability-protected “ERP UI Studio” in `wp-admin` for a controlled set of visual changes. Use ordinary WordPress Pages for content pages such as help, onboarding, and announcements; a dynamic ERP block on a normal Page is an optional integration if page-level composition is specifically wanted, not a requirement for every operational screen. Gutenberg can edit the page and the block's exposed attributes, but does not automatically turn a plugin-rendered transaction form into freely editable blocks.

## What the official docs show

### ERPNext / Frappe

- ERPNext organizes its app around module Workspaces. A Workspace can contain a dashboard, shortcuts, links, masters, and reports. Users can personalize a Standard Workspace for themselves; a Custom Workspace can be marked standard/default for all users. This is in-app workspace configuration, rather than a collection of WordPress content pages. ([ERPNext: Customizing Workspace](https://docs.frappe.io/erpnext/workspace))
- Customize Form lets a user add custom fields, reorder fields, and change supported properties of standard fields. Frappe's developer guidance distinguishes schema/property customization from behavior: use hooks to add validation or business logic rather than trying to express that logic as a form customization. ([ERPNext: Customize Form](https://docs.frappe.io/erpnext/customize-form), [Frappe Framework: Customization](https://docs.frappe.io/framework-git/doctypes/customization))
- ERPNext documents authorization separately through roles, document types, field permission levels, document stages, and User Permissions that can restrict which records a user sees. ([ERPNext: Role Based Permissions](https://docs.frappe.io/erpnext/permissions))

**Synthesis:** users can rearrange the work surface and approved metadata; custom behavior and access policy have their own mechanisms.

### Microsoft Dynamics 365 Finance & Operations

- Dynamics has multiple personalization levels. Even users with restricted personalization can retain ordinary usage choices such as column width, filter, and expanded sections. Full personalization adds page tools for hiding, moving, relabeling, locking, or requiring supported controls, adding fields already in the page definition, and arranging workspaces/dashboards. While the personalization toolbar is open, the underlying page is read-only. ([Microsoft Learn: Personalize the user experience](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/get-started/personalize-user-experience))
- Personalizations can be personal or shared. For pages with saved views, an authorized publisher can publish views to users with selected security roles; privileged users can also copy or administer personalizations. ([Microsoft Learn: Share personalizations](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/get-started/personalize-user-experience#share-personalizations))
- Role-based security is documented independently: roles aggregate duties and privileges, permissions cover entry points and objects such as tables, fields, forms, and server methods, while data security policies limit records. The role controls what parts of the interface are visible as well as which duties are permitted. ([Microsoft Learn: Role-based security](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/role-based-security))

**Inference:** screen personalization is a scoped presentation layer; publishing a view to a role does not replace authorization on the business objects and data.

### Odoo

- Odoo Studio is an in-product, no-code customization tool. Its field documentation lists per-view choices such as labels, widgets, visibility by user group, required/read-only state, and whether a field is invisible. ([Odoo 18: Studio](https://www.odoo.com/documentation/18.0/applications/studio.html), [Odoo 18: Fields and widgets](https://www.odoo.com/documentation/18.0/applications/studio/fields.html))
- Odoo's access-rights docs separately describe administrator-managed groups, application/model access rights, menu and view access, and record rules that refine visibility/editing of records. ([Odoo 18: Access Rights](https://www.odoo.com/documentation/18.0/applications/general/users/access_rights.html))
- Dashboard customization is also permission-gated: Odoo says only users with `Dashboards / Admin` access can customize dashboards or dashboard settings. A dashboard may be visible while the user still lacks permission to see the underlying data. ([Odoo 18: Dashboards](https://www.odoo.com/documentation/18.0/applications/productivity/dashboards.html))

**Inference:** visual visibility and actual data permissions are separate concerns; hiding a control is not evidence that the underlying operation is denied.

## WordPress fit

WordPress itself supports the hybrid model:

- Its developer handbook recommends keeping custom post type functionality in a plugin so content remains portable across theme changes. ([WordPress Plugin Handbook: Registering Custom Post Types](https://developer.wordpress.org/plugins/post-types/registering-custom-post-types/))
- Plugins can register admin option pages, and WordPress provides the Settings API and Options API for building those settings screens. Admin menu registration accepts a capability; the capability documentation says plugins should check capabilities whenever users submit data. ([WordPress Plugin Handbook: Administration Menus](https://developer.wordpress.org/plugins/administration-menus/), [Custom Settings Page](https://developer.wordpress.org/plugins/settings/custom-settings-page/), [Checking User Capabilities](https://developer.wordpress.org/plugins/security/checking-user-capabilities/))
- A plugin can register a dynamic Gutenberg block whose saved attributes are rendered on the server. This gives a page editor a bounded set of block attributes while the plugin supplies the rendered output. ([WordPress Block Editor Handbook: Static or Dynamic Rendering](https://developer.wordpress.org/block-editor/getting-started/fundamentals/static-dynamic-rendering/))

**Inference for Mini ERP:**

1. Keep inventory, cash, order, approval, role, and row-scope behavior inside the plugin. The current route-per-screen application structure is compatible with the sampled ERP pattern; converting every operational route into a WordPress Page is optional and does not, by itself, expose the internals of a custom transaction form.
2. Give the dynamic ERP block a small, typed allowlist of presentation attributes—for example, optional introduction text, a page heading, or which approved dashboard cards to show. The renderer must still check the current ERP actor's capability and data scope before loading or mutating business data. A hidden or omitted block/action is only presentation; it is not the security check.
3. Add an `ERP UI Settings` screen under `wp-admin` for authorized UI editors. Persist safe settings through the WordPress settings/options mechanism and expose only documented choices such as logo, brand palette mapped to existing design tokens, help text, dashboard-card selection, and approved navigation labels/order. Use a dedicated UI-customization capability; do not grant `manage_options` or ERP business capabilities merely to change the UI.
4. Treat changes to transaction fields, validation, posting rules, approval transitions, and access scopes as application configuration or developer-owned changes with validation and audit rules, not arbitrary page-builder edits. Frappe's own guidance explicitly separates metadata customization from custom behavior; the same boundary is a useful fit here.
5. If the company only wants editable help content, branding, or a public landing page, ordinary WordPress Pages are sufficient. If it wants forms, calculations, status transitions, stock/cash side effects, and protected data, use a plugin-rendered ERP screen with a bounded editor surface.

## Recommendation

The plugin choice is reasonable for owning critical ERP functionality; the gap is that today's custom routes do not provide a supported customization surface. **Recommended direction:** retain the plugin-owned ERP routes and build an explicit, capability-protected ERP UI Studio in `wp-admin`. Start with brand tokens, approved labels and help text, dashboard cards, navigation ordering, and safe per-user or role-level table/view preferences. Consider field order and visibility only through typed configuration that preserves mandatory-field and workflow invariants. Use standard WordPress Pages for ordinary editable content, and add a dynamic ERP block to a Page only when page-level composition has a concrete use. This follows the bounded-customization pattern without asking Gutenberg to become the authorization or transaction engine.

This recommendation is an inference from the official vendor and WordPress documentation above, not a claim that the current Mini ERP already implements those editor features.

## Limits

- The sources describe supported product behavior, not an independent comparison of ease of use, safety, or adoption.
- ERPNext, Dynamics, and Odoo features vary by release, licensing, enabled modules, user privileges, and configuration.
- This note does not evaluate every ERP vendor, every page builder, or Mini ERP's runtime. It supports an architecture decision, not acceptance evidence for an implementation.
