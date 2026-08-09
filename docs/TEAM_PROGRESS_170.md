# AIMWWeb Team Progress - 170

## CNT-009 Direct post/page editing hardening

Status: Implemented - CI validation pending

## Scope
The direct WordPress post/page editor already supported loading and saving live content, but save requests could silently overwrite a newer remote edit because the loaded `modified_gmt` value was not used as a concurrency baseline.

## Implementation
- Extended `WordPressContentUpdateRequest` with optional `ExpectedModifiedGmt` and explicit `ForceOverwrite` fields while keeping existing callers backward compatible.
- `WordPressPostEditorWebService` now remembers the `modified_gmt` version loaded into the current scoped editor session.
- Normal save performs a fresh `context=edit` preflight read and compares the remote `modified_gmt` with the editor baseline before POSTing changes.
- A changed remote version returns a typed `Conflict` result and does not send the update request, preserving the user's unsaved editor state instead of silently overwriting WordPress.
- Successful saves advance the in-session baseline to the returned WordPress `modified_gmt`, so subsequent saves remain protected.
- Added server-side validation for supported content statuses in addition to the existing required-title validation.
- The request contract includes an explicit force-overwrite flag for a future deliberate conflict-resolution UI; normal direct editing never enables it implicitly.

## Acceptance coverage
- Remote change after editor load -> Conflict result and zero update POSTs.
- Unchanged remote version -> update POST succeeds and returns the new modification version.
- Unsupported WordPress status -> validation failure before any remote request.
- Equivalent timestamps with different timezone offsets compare as the same instant.

## Roadmap boundary
- This completes the safety/validation acceptance gap for `CNT-009` Direct post/page editing once CI is green.
- `CNT-012` Synchronization conflict resolution remains separate/planned because a full side-by-side compare/merge resolution workflow is not included here.

## Release
- Web version: `155.117.0`.

## Validation gate
1. Full solution build including Razor compilation.
2. Full automated test suite including editor conflict tests.
3. Build workflow green.
4. .NET Build Verification green.
5. Merge only after both gates pass.
