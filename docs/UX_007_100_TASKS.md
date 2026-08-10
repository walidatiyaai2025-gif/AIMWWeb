# UX-007 — 100 Loading, Empty, Success, Warning, Offline & Error State Tasks

Issue: #52  
Branch: `agent/ux-007-feedback-states`  
Scope: shared feedback-state taxonomy, loading/empty/retry behavior, retained-content warnings, stale/cached and partial-failure presentation, responsive/accessibility resilience, and targeted production adoption without changing business or service contracts.

## Shared feedback-state taxonomy

- [x] 01. Add one reusable `AppStatePanel` contract for blocking and section-level feedback.
- [x] 02. Normalize supported state kinds instead of allowing arbitrary styling semantics.
- [x] 03. Support `info` as the neutral feedback fallback.
- [x] 04. Support `loading` as a first-class state.
- [x] 05. Support `empty` as a first-class non-error state.
- [x] 06. Support `success` as a first-class state.
- [x] 07. Support `warning` as a first-class state.
- [x] 08. Support `error` and the `danger` alias as one error state.
- [x] 09. Support `offline` for consumers that can truthfully detect connectivity state.
- [x] 10. Support `cached` and `stale` aliases for retained-data freshness UX.
- [x] 11. Support `partial` for mixed-success outcomes.
- [x] 12. Expose normalized state through `data-state` for stable styling/testing.
- [x] 13. Expose blocking intent through `data-blocking` without inventing application logic.
- [x] 14. Keep state titles mandatory and descriptions optional.
- [x] 15. Keep custom icons optional while providing state-specific fallback icons.

## Semantics, recovery and diagnostics

- [x] 16. Use polite `status` semantics for normal informational feedback.
- [x] 17. Use assertive `alert` semantics for error feedback.
- [x] 18. Allow an explicit assertive override for genuinely urgent consumers.
- [x] 19. Mark state panels atomic so title and recovery context are announced together.
- [x] 20. Expose busy state through `aria-busy`.
- [x] 21. Add dedicated recovery guidance rather than burying next steps in error text.
- [x] 22. Make the recovery label configurable for bilingual call sites.
- [x] 23. Add a retry action to the shared state contract.
- [x] 24. Prevent retry double activation while busy.
- [x] 25. Provide separate idle and retrying action labels.
- [x] 26. Allow a dedicated retry accessible name.
- [x] 27. Keep arbitrary secondary recovery actions available through a fragment.
- [x] 28. Add optional technical-details disclosure without forcing raw diagnostics into the primary message.
- [x] 29. Keep long technical details wrapped inside the component boundary.
- [x] 30. Add optional successful-update timestamp and freshness copy.

## Loading and skeleton behavior

- [x] 31. Give loading state a shared non-text visual spinner.
- [x] 32. Keep the spinner decorative to assistive technology.
- [x] 33. Add reusable `AppSkeleton` for structured loading placeholders.
- [x] 34. Mark skeleton placeholders presentation-only and hidden from assistive technology.
- [x] 35. Clamp skeleton line count to a safe bounded range.
- [x] 36. Shorten the final skeleton line for recognizable content shape.
- [x] 37. Add shimmer only as progressive visual feedback, not as state meaning.
- [x] 38. Disable spinner animation when reduced motion is requested.
- [x] 39. Disable skeleton animation when reduced motion is requested.
- [x] 40. Preserve a static skeleton shape when motion is disabled.
- [x] 41. Route legacy `AppLoading` through the shared state panel contract.
- [x] 42. Preserve existing `AppLoading` title/message/class parameters.
- [x] 43. Add optional blocking semantics to `AppLoading`.
- [x] 44. Add opt-in skeleton support to `AppLoading`.
- [x] 45. Make skeleton line count configurable per loading surface.

## Empty and no-data behavior

- [x] 46. Route legacy `AppEmptyState` through the shared state panel contract.
- [x] 47. Preserve existing `AppEmptyState` title/description/icon/class/action parameters.
- [x] 48. Add recovery guidance to empty states.
- [x] 49. Add configurable recovery labels for Arabic/English parity.
- [x] 50. Let empty states declare non-blocking section intent.
- [x] 51. Avoid rendering an empty actions container when no actions exist.
- [x] 52. Keep empty state visually distinct from error state without relying only on color.
- [x] 53. Keep empty state semantically a polite status rather than an alert.
- [x] 54. Automatically improve `AppDataGrid` loading states through the wrapper upgrade.
- [x] 55. Automatically improve `AppDataGrid` true-empty states through the wrapper upgrade.
- [x] 56. Automatically improve `AppDataGrid` filtered no-results states through the wrapper upgrade.
- [x] 57. Preserve existing grid empty/no-results behavior and actions.
- [x] 58. Keep no-data guidance actionable where a next step exists.
- [x] 59. Preserve source compatibility for current empty-state consumers.
- [x] 60. Preserve source compatibility for current loading-state consumers.

## Retained-content, stale and partial feedback

- [x] 61. Add reusable `AppStateBanner` for non-blocking retained-content feedback.
- [x] 62. Mark banner semantics with `data-retains-content=true`.
- [x] 63. Support neutral information banners.
- [x] 64. Support success banners.
- [x] 65. Support warning banners.
- [x] 66. Support assertive error banners when appropriate.
- [x] 67. Support offline banners only for consumers with real connectivity knowledge.
- [x] 68. Support cached/stale retained-data banners.
- [x] 69. Support partial-failure banners while keeping successful content visible.
- [x] 70. Give banners the same polite/assertive semantic rules as panels.
- [x] 71. Expose banner busy state through `aria-busy`.
- [x] 72. Add guarded retry behavior to banners.
- [x] 73. Add optional banner freshness wording.
- [x] 74. Allow secondary banner actions without page-local state markup.
- [x] 75. Keep banners compact enough to sit above retained operational content.

## Visual, responsive, RTL and accessibility resilience

- [x] 76. Use a non-color leading edge and icon to identify state categories.
- [x] 77. Use logical `border-inline-start` so state emphasis mirrors in RTL automatically.
- [x] 78. Keep state title, description and recovery copy safe for long wrapping text.
- [x] 79. Keep diagnostic text bounded and wrapping instead of causing page overflow.
- [x] 80. Stack state-panel recovery actions on narrow screens.
- [x] 81. Stack retained-banner actions on narrow screens.
- [x] 82. Collapse panel layout safely to one column on phones.
- [x] 83. Preserve readable banner hierarchy when wrapping on phones.
- [x] 84. Add forced-colors borders for state panels.
- [x] 85. Add forced-colors treatment for state icons and spinners.
- [x] 86. Add forced-colors treatment for retained banners.
- [x] 87. Add forced-colors treatment for skeleton placeholders.
- [x] 88. Keep retry/recovery controls aligned with the shared practical touch-target system.
- [x] 89. Load feedback-state CSS after accessibility/forms so the final state layer is deterministic.
- [x] 90. Keep the feedback layer theme-token based and independent from one accent color.

## Production adoption and regression protection

- [x] 91. Replace AI Usage first-load markup with shared loading state plus skeletons.
- [x] 92. Replace AI Usage initial blocking error with actionable shared error/retry state.
- [x] 93. Replace AI Usage null snapshot markup with an actionable shared empty state.
- [x] 94. Keep the last successful AI Usage snapshot visible during refresh instead of blanking the workspace.
- [x] 95. Show failed refresh as cached/stale retained-data feedback with last successful refresh time.
- [x] 96. Surface failed AI calls as a partial-failure banner while preserving successful telemetry.
- [x] 97. Replace provider and operation subsection empty markup with non-blocking shared empty states.
- [x] 98. Keep AI Usage state copy bilingual and avoid falsely labeling generic service failures as offline.
- [x] 99. Add deterministic feedback-state contract tests, including an exact 100-task manifest guard.
- [x] 100. Record the UX-007 compatibility boundary: presentation/state UX only; no database, auth, tenant, AI runtime, persistence, or WordPress execution contract changes.

## Compatibility boundary

UX-007 changes presentation-state semantics, retry affordances, retained-content behavior, loading placeholders, freshness communication, responsive styling and accessibility metadata. Existing services remain authoritative. No database schema, tenant ownership, authentication model, API contract, AI runtime routing, persistence contract, or WordPress execution contract is intentionally changed.
