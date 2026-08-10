# UX-005 — 100 Forms, Validation & Confirmation UX Tasks

Issue: #50  
Branch: `agent/ux-005-forms-validation-confirmations`  
Scope: shared form semantics, validation, submit states, destructive confirmations, recovery guidance, bilingual keyboard UX, and targeted high-risk adoption without changing domain/service contracts.

## Shared field contract

- [x] 01. Add a reusable `AppFormField` primitive instead of page-local label patterns.
- [x] 02. Give every shared field an explicit `InputId` contract.
- [x] 03. Associate field labels with controls through `for`/ID semantics.
- [x] 04. Add a visible required marker that is hidden from assistive technology.
- [x] 05. Add separate screen-reader wording for required fields.
- [x] 06. Add optional-field wording without weakening the primary label.
- [x] 07. Add reusable helper-text rendering below labels.
- [x] 08. Add reusable character/constraint hint rendering.
- [x] 09. Add reusable field-level error rendering with alert semantics.
- [x] 10. Expose deterministic helper, hint, and error IDs for ARIA relationships.
- [x] 11. Add a field-level invalid visual state independent of browser defaults.
- [x] 12. Add a field-level disabled visual state.
- [x] 13. Keep field content pluggable through `RenderFragment` so existing input types remain usable.
- [x] 14. Make field labels and helper copy wrap safely for Arabic and English.
- [x] 15. Keep field layout logical-property based so RTL does not require duplicate markup.

## Validation and status

- [x] 16. Add a reusable `AppValidationSummary` component.
- [x] 17. Give validation summaries assertive live-region semantics.
- [x] 18. Make validation summaries programmatically focusable.
- [x] 19. Mark validation summaries for shared runtime discovery.
- [x] 20. Add opt-in/opt-out auto-focus metadata for validation summaries.
- [x] 21. Deduplicate repeated validation messages before rendering.
- [x] 22. Add a localized-ready validation-summary title contract.
- [x] 23. Add optional validation-summary recovery/description copy.
- [x] 24. Add a reusable `AppFormStatus` component for post-action feedback.
- [x] 25. Use `role=alert` for form errors.
- [x] 26. Use polite `role=status` behavior for non-error form outcomes.
- [x] 27. Add optional recovery guidance to form status messages.
- [x] 28. Add distinct success, warning, error, and info status tones.
- [x] 29. Keep status icons supplemental and non-essential to meaning.
- [x] 30. Keep form status content bilingual-ready without embedded English-only workflow logic.

## Submit, cancel, and busy state

- [x] 31. Add a reusable `AppFormActions` component.
- [x] 32. Expose a single shared busy state for action groups.
- [x] 33. Disable save while a save is already running.
- [x] 34. Disable cancel while a blocking save is running.
- [x] 35. Prevent shared save callbacks from firing twice while busy.
- [x] 36. Prevent shared cancel callbacks from firing while blocked.
- [x] 37. Support separate idle and saving button text.
- [x] 38. Support explicit accessible save/cancel names.
- [x] 39. Support configurable save icon and visual variant.
- [x] 40. Support screens where cancel is intentionally omitted.
- [x] 41. Add an unsaved/dirty state indicator contract.
- [x] 42. Make the dirty state a screen-reader status instead of color-only decoration.
- [x] 43. Add contextual form-action hint text.
- [x] 44. Make action buttons wrap rather than overflow on narrow screens.
- [x] 45. Stack form actions into full-width mobile controls at phone widths.

## Destructive confirmation hardening

- [x] 46. Extend `AppConfirmDialog` without breaking existing call sites.
- [x] 47. Add explicit destructive-action metadata to confirmation dialogs.
- [x] 48. Add configurable impact guidance to confirmations.
- [x] 49. Add configurable recovery guidance to confirmations.
- [x] 50. Add separate localized-ready impact and recovery labels.
- [x] 51. Add optional typed confirmation for high-risk actions.
- [x] 52. Keep typed confirmation disabled until the expected text matches.
- [x] 53. Support case-sensitive confirmation by default.
- [x] 54. Add an optional case-insensitive confirmation mode.
- [x] 55. Add field-level mismatch feedback for typed confirmation.
- [x] 56. Reset typed confirmation state when a dialog opens fresh.
- [x] 57. Reset typed confirmation state when a dialog closes.
- [x] 58. Prevent confirm execution while the dialog is busy.
- [x] 59. Prevent backdrop/close actions while destructive work is busy.
- [x] 60. Add configurable close, confirm, and cancel accessible names.

## Runtime and visual resilience

- [x] 61. Add a shared `form-ux.js` runtime rather than page-specific focus scripts.
- [x] 62. Discover controls marked `aria-invalid=true`.
- [x] 63. Include native `:invalid` controls in invalid-control discovery.
- [x] 64. Ignore disabled controls when selecting an invalid focus target.
- [x] 65. Ignore hidden controls when selecting an invalid focus target.
- [x] 66. Expose `focusFirstInvalid` for future form integrations.
- [x] 67. Mark native invalid events with `aria-invalid=true`.
- [x] 68. Remove stale native invalid state after a control becomes valid.
- [x] 69. Observe newly rendered validation summaries after Blazor rerenders.
- [x] 70. Move keyboard focus to a newly rendered auto-focus validation summary.
- [x] 71. Avoid repeatedly focusing the same validation-summary DOM instance.
- [x] 72. Add a shared `forms-ux.css` final design-system layer.
- [x] 73. Enforce practical 44px minimum height for text/select/textarea controls.
- [x] 74. Add themed hover/focus-compatible form borders.
- [x] 75. Add a non-color invalid border plus focus halo.
- [x] 76. Add disabled-control styling and cursor feedback.
- [x] 77. Add mobile one-column form grids.
- [x] 78. Add reduced-motion behavior for form transitions.
- [x] 79. Add forced-colors treatment for invalid fields and summaries.
- [x] 80. Load form CSS/JS after the shared accessibility hardening layer.

## Account security adoption

- [x] 81. Migrate account password fields to shared form-field semantics.
- [x] 82. Add local required validation for the current password.
- [x] 83. Add local minimum-length validation for the new password.
- [x] 84. Add local uppercase/lowercase/numeric validation matching the existing service contract.
- [x] 85. Reject reuse of the current password before calling the service.
- [x] 86. Validate password confirmation before calling the service.
- [x] 87. Mark password inputs with field-specific `aria-invalid` state.
- [x] 88. Add a shared password validation summary and recovery message.
- [x] 89. Disable password submission until the user has entered form content.
- [x] 90. Clear validation state after a successful password change.

## High-risk administration adoption and regression coverage

- [x] 91. Validate every AI provider model before persistence calls begin.
- [x] 92. Replace provider model/API-key inputs with shared labelled helper/error patterns.
- [x] 93. Require a separate destructive confirmation when stored AI keys are marked for removal.
- [x] 94. Require typing `REMOVE` before stored AI keys can be deleted and settings saved.
- [x] 95. Explain AI-key removal impact and recovery before destructive execution.
- [x] 96. Add preflight validation to application-user create/edit forms.
- [x] 97. Add a confirmation dialog before disabling an application user.
- [x] 98. Add validation plus username-typed confirmation before administrator password reset.
- [x] 99. Add deterministic `FormUxContractTests` covering shared primitives, runtime, CSS, and all three high-risk page adoptions.
- [x] 100. Add a regression guard asserting this delivery manifest contains exactly 100 completed implementation tasks.

## Compatibility boundary

UX-005 changes presentation, preflight validation, focus behavior, and confirmation UX only. Existing application services remain authoritative for persistence and security rules. No database schema, tenant ownership, authentication model, API contract, AI runtime routing, or WordPress execution contract is intentionally changed.
