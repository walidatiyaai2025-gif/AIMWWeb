# UX-008 — 100 RTL/LTR Parity Code Tasks

Exactly 100 completed code tasks for Arabic RTL / English LTR visual parity. The implementation keeps one component/layout tree and fixes direction through shared primitives, logical CSS, runtime metadata, targeted production adoption, and regression guards.

## Direction root and runtime — 1–10
- [x] 001 Add initial `data-app-language` metadata to the document root.
- [x] 002 Add initial `data-app-direction` metadata to the document root.
- [x] 003 Mirror language metadata onto `body`.
- [x] 004 Mirror direction metadata onto `body`.
- [x] 005 Synchronize metadata whenever `appLanguage.apply` runs.
- [x] 006 Synchronize metadata before language-toggle reload.
- [x] 007 Add a shared `appBidi.sync()` runtime contract.
- [x] 008 Observe root `lang` and `dir` mutations for parity changes.
- [x] 009 Dispatch a direction-change event after runtime synchronization.
- [x] 010 Expose logical inline-start/inline-end helpers from the bidi runtime.

## Bidi text isolation primitive — 11–20
- [x] 011 Add reusable `AppBidiText` based on semantic `bdi` isolation.
- [x] 012 Support automatic direction mode for natural-language mixed content.
- [x] 013 Support explicit RTL text mode.
- [x] 014 Support explicit LTR text mode.
- [x] 015 Support technical/code/path/url/email aliases as LTR-isolated content.
- [x] 016 Support number/date/time/version aliases as LTR-isolated numeric content.
- [x] 017 Preserve optional language metadata on isolated values.
- [x] 018 Allow render-fragment content inside the bidi primitive.
- [x] 019 Allow plain text content inside the bidi primitive.
- [x] 020 Add long-value wrapping without allowing bidi spill into surrounding labels.

## Directional icons and shared buttons — 21–30
- [x] 021 Add reusable `AppDirectionalIcon`.
- [x] 022 Add neutral icon intent that never mirrors automatically.
- [x] 023 Add previous/back icon intent.
- [x] 024 Add next/forward icon intent.
- [x] 025 Add Enter icon intent.
- [x] 026 Keep external-link icon intent non-mirrored by default.
- [x] 027 Add optional explicit icon mirror override.
- [x] 028 Add `IconIntent` to shared `AppButton` without breaking existing call sites.
- [x] 029 Add `MirrorIconInRtl` override to shared `AppButton`.
- [x] 030 Emit directional-icon metadata/classes from shared button icons.

## Shell, navigation, breadcrumb and popovers — 31–40
- [x] 031 Make shared shell surfaces text-align from logical start.
- [x] 032 Make navigation labels align from logical start.
- [x] 033 Keep navigation chevrons at logical inline-end.
- [x] 034 Keep topbar actions at logical inline-end on wide layouts.
- [x] 035 Mirror breadcrumb direction glyphs in RTL.
- [x] 036 Anchor theme popovers to logical inline-end.
- [x] 037 Anchor account popovers to logical inline-end.
- [x] 038 Anchor notification popovers to logical inline-end.
- [x] 039 Anchor recent/quick-action popovers to logical inline-end.
- [x] 040 Isolate command-palette route/path text from Arabic labels.

## Forms and dialogs — 41–50
- [x] 041 Standardize form-field text alignment to logical start.
- [x] 042 Standardize validation-summary alignment to logical start.
- [x] 043 Keep form actions on logical end for desktop parity.
- [x] 044 Force email input values to isolated LTR.
- [x] 045 Force URL input values to isolated LTR.
- [x] 046 Force telephone input values to isolated LTR.
- [x] 047 Force numeric input values to isolated LTR with tabular figures.
- [x] 048 Add optional direction override to `AppDialog`.
- [x] 049 Keep dialog close control on logical inline-end.
- [x] 050 Keep dialog technical diagnostics LTR without changing surrounding language.

## Dense tables, filters and paging — 51–60
- [x] 051 Align data-grid command bars from logical start.
- [x] 052 Keep data-grid command actions on logical inline-end.
- [x] 053 Align table headers and cells from logical start.
- [x] 054 Preserve centered selection-checkbox columns in both directions.
- [x] 055 Isolate page-number summaries as LTR numeric sequences.
- [x] 056 Mirror existing previous/next paging glyphs in RTL.
- [x] 057 Keep filter summaries aligned from logical start.
- [x] 058 Keep mobile data-grid cards aligned from logical start.
- [x] 059 Preserve horizontal overscroll containment for RTL tables.
- [x] 060 Keep bulk-action surfaces aligned consistently in both directions.

## Feedback, badges and technical values — 61–70
- [x] 061 Keep state-panel semantic accent on logical inline-start.
- [x] 062 Keep state-banner semantic accent on logical inline-start.
- [x] 063 Keep alert semantic accent on logical inline-start.
- [x] 064 Keep notification semantic accent on logical inline-start.
- [x] 065 Isolate freshness timestamps as LTR values.
- [x] 066 Add optional bidi mode to shared `AppBadge`.
- [x] 067 Render badge text through `AppBidiText` when bidi mode is requested.
- [x] 068 Apply tabular numeric treatment to numeric/version badges.
- [x] 069 Isolate code/pre/kbd/samp technical content globally.
- [x] 070 Prevent long technical values from forcing horizontal page overflow.

## Build/release production adoption — 71–80
- [x] 071 Mark Build/Release page as an explicit bidi scope.
- [x] 072 Isolate the current version badge.
- [x] 073 Isolate the version summary card.
- [x] 074 Isolate the Git branch summary card.
- [x] 075 Isolate the commit summary card.
- [x] 076 Isolate build timestamp display.
- [x] 077 Isolate release dates inside mixed-language release headings.
- [x] 078 Isolate release-version badges inside release history.
- [x] 079 Isolate assembly/informational-version/API-path technical details.
- [x] 080 Keep copied build-report content unchanged while improving only presentation direction.

## Responsive, accessibility and resilience — 81–90
- [x] 081 Preserve one DOM/component hierarchy for RTL and LTR.
- [x] 082 Use logical CSS properties instead of duplicating Arabic layouts.
- [x] 083 Reconcile mobile topbar action margins using logical spacing.
- [x] 084 Reconcile mobile data-grid action margins using logical spacing.
- [x] 085 Move responsive sidebar close control to the appropriate physical safe-area edge per direction.
- [x] 086 Stack phone dialog/form actions without reversing action semantics.
- [x] 087 Disable directional-icon transitions under reduced-motion preference.
- [x] 088 Preserve semantic inline-start borders in forced-colors mode.
- [x] 089 Keep bidi-isolated values compatible with keyboard/screen-reader reading order.
- [x] 090 Expose runtime direction metadata for future direction-aware features without JS DOM duplication.

## Regression guards and compatibility — 91–100
- [x] 091 Load the RTL/LTR parity stylesheet after accessibility/forms/feedback layers.
- [x] 092 Load bidi runtime immediately after language runtime.
- [x] 093 Add contract coverage for root direction metadata.
- [x] 094 Add contract coverage for bidi runtime synchronization and helpers.
- [x] 095 Add contract coverage for `AppBidiText` modes and semantic isolation.
- [x] 096 Add contract coverage for directional icon/button mirroring contracts.
- [x] 097 Add contract coverage for dialog/badge direction extensions.
- [x] 098 Add contract coverage for logical CSS, responsive, reduced-motion and forced-colors behavior.
- [x] 099 Add contract coverage for Build/Release production adoption.
- [x] 100 Add an exact-100 manifest guard and preserve service/database/auth/API contracts unchanged.

## Compatibility boundary

UX-008 is presentation-direction hardening only. It intentionally changes no database schema, tenant ownership, authentication model, API contract, AI runtime routing, persistence contract, WordPress execution contract, release-note data model, or copied build-report payload. Browser-driven screenshot/visual-diff automation remains UX-010 scope and is not falsely claimed here.
