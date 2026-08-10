# UX-004 — 50 Accessibility Hardening Tasks

Issue: #49  
Branch: `agent/ux-004-accessibility-hardening`  
Target: WCAG 2.2 AA-oriented shared UI hardening without domain/API/persistence changes.

## Completed implementation checklist

- [x] 01. Add a reusable visually-hidden (`sr-only`) utility for screen-reader-only copy.
- [x] 02. Add a shared polite live region for runtime announcements.
- [x] 03. Enforce visible `:focus-visible` treatment across links, buttons, form controls, summaries and tabindex targets.
- [x] 04. Add focus-ring offset/contrast so keyboard focus remains visible against themed surfaces.
- [x] 05. Respect operating-system `prefers-reduced-motion` across animations, transitions and scrolling.
- [x] 06. Respect the application's persisted reduced-motion accessibility preference.
- [x] 07. Add `prefers-contrast: more` treatment for focus and selected operational rows.
- [x] 08. Add Windows/high-contrast `forced-colors` support for focus and selected states.
- [x] 09. Give selected data rows a structural inset indicator so selection is not communicated by color alone.
- [x] 10. Enforce practical 44px targets for shared close, clear and accessibility controls.
- [x] 11. Make the route body the actual `main` landmark instead of wrapping the topbar inside `main`.
- [x] 12. Promote the shared route title to an identifiable page `h1`.
- [x] 13. Associate the main landmark with the current page title through `aria-labelledby`.
- [x] 14. Convert the breadcrumb container into a navigation landmark.
- [x] 15. Mark the breadcrumb destination with `aria-current="page"`.
- [x] 16. Expose local-system running state as a screen-reader status.
- [x] 17. Give the command trigger `aria-haspopup`, `aria-controls` and live `aria-expanded` state.
- [x] 18. Expose the Ctrl+K command shortcut through `aria-keyshortcuts`.
- [x] 19. Expose the Ctrl+Shift+P recent/favorites shortcut through `aria-keyshortcuts`.
- [x] 20. Give the command palette a stable dialog ID and labelled title.
- [x] 21. Give the command palette explicit keyboard-help description semantics.
- [x] 22. Mark the command search input as the preferred modal autofocus target.
- [x] 23. Make command-result changes a polite live region.
- [x] 24. Expose the no-results command state with `role="status"`.
- [x] 25. Mark the command close button for generic Escape handling.
- [x] 26. Add a runtime that discovers any visible `aria-modal="true"` dialog centrally.
- [x] 27. Focus the preferred/first interactive target when a modal opens.
- [x] 28. Trap forward Tab navigation at the last modal focus target.
- [x] 29. Trap Shift+Tab navigation at the first modal focus target.
- [x] 30. Prevent programmatic/pointer focus from escaping the active modal.
- [x] 31. Restore focus to the original opener when a modal closes.
- [x] 32. Lock background body scrolling while modal dialogs are active.
- [x] 33. Keep modal state in a stack-safe map so nested dialogs do not corrupt focus restoration.
- [x] 34. Observe shared page-title changes after client-side route navigation.
- [x] 35. Move focus to `#main-content` after a route page-title change.
- [x] 36. Announce the new page title after client-side route navigation.
- [x] 37. Give every shared `AppDialog` instance stable unique title/description IDs.
- [x] 38. Use `aria-labelledby` for `AppDialog` when no explicit aria label is supplied.
- [x] 39. Use `aria-describedby` for `AppDialog` subtitles.
- [x] 40. Add configurable `CloseAriaLabel` to remove hard-coded dialog close wording.
- [x] 41. Add explicit/fallback accessible names to shared `AppButton`.
- [x] 42. Expose shared button busy state with `aria-busy`.
- [x] 43. Add optional `aria-pressed` support for shared toggle buttons.
- [x] 44. Remove href/tab-stop behavior from disabled shared anchor-buttons.
- [x] 45. Expose shared search busy state with `aria-busy`.
- [x] 46. Announce active shared search work through a polite status node.
- [x] 47. Add configurable clear-search accessible wording instead of a hard-coded label.
- [x] 48. Give shared data grids busy state, table naming and total filtered row-count semantics.
- [x] 49. Add accessible grid selection, row labels, page-size labels, pagination names and polite summary/page updates.
- [x] 50. Harden the accessibility settings panel with dialog semantics, labelled controls, `aria-pressed`, shortcut metadata, Escape close, focus restore and focus preservation after settings changes.

## Boundary

These tasks intentionally do not change authentication, tenant ownership, WordPress execution, API contracts, persistence, billing or AI orchestration. Browser-level automated axe/visual baselines remain the dedicated UX-010 workstream; UX-004 establishes deterministic shared contracts that UX-010 can exercise.
