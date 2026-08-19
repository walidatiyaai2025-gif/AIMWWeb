# UX-010 Hardening — 10 Completed Tasks

This focused batch is stacked on UX-010 and extends the existing Playwright accessibility regression gate without changing production authentication, tenant ownership, persistence, API, AI, approval, or WordPress execution contracts.

- [x] UX010-HARD-001 Detect empty or broken `aria-labelledby` references.
- [x] UX010-HARD-002 Detect empty or broken `aria-describedby` references.
- [x] UX010-HARD-003 Detect empty or broken `aria-controls` references.
- [x] UX010-HARD-004 Detect empty or broken `aria-owns` references.
- [x] UX010-HARD-005 Require visible enabled `role=button` controls to be keyboard focusable.
- [x] UX010-HARD-006 Require visible dialogs and alert dialogs to expose an accessible name.
- [x] UX010-HARD-007 Require visible iframes to expose a non-empty title.
- [x] UX010-HARD-008 Reject focusable content inside visible `aria-hidden=true` subtrees.
- [x] UX010-HARD-009 Require visible `role=img` elements to expose an accessible name.
- [x] UX010-HARD-010 Validate enumerated values for `aria-expanded`, `aria-selected`, `aria-pressed`, and `aria-checked`.

## Validation scope

The hardening audit is executed against every public route in `UxRouteCatalog.PublicRoutes` and every authenticated route in `UxRouteCatalog.AuthenticatedRoutes`. Core contract tests guard the exact ten-rule implementation and the exact ten completed-task manifest.

## Team coordination

Implementation branch: `agent/ux-010-hardening-10`  
Base implementation head: `813cfa970e11126cf833c02031ec53ea32f111f5` from `agent/ux-010-regression-gates`.  
Merge target: `agent/ux-010-regression-gates` so the batch can be integrated without duplicating or overwriting the team's active UX-010 work.
