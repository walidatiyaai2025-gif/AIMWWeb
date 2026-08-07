# Engineering Review 156

## Current focus
PLT-004 Localization and platform stabilization.

## Findings
- Localization is already partially implemented.
- Full RTL/LTR audit is required across all UI surfaces.
- Translation keys should remain centralized.
- Every new UI change must include Arabic and English labels.

## Next implementation sequence
1. Identify shared localization service and resource files.
2. Audit navigation, dashboard, forms, dialogs and validation messages.
3. Add missing translations only where required.
4. Validate RTL layout direction changes.
5. Record verification evidence before marking complete.

## Definition of done
- Arabic and English text available.
- RTL/LTR switching tested.
- No hard-coded user-facing strings introduced.
- Build verification recorded.
