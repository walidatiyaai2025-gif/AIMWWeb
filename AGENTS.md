# AIMWWeb Agent Instructions

## Source of truth
- Reconstruct the current repository state from GitHub before acting. Do not treat chat memory or stale task trackers as authoritative.
- `main`, merged pull requests, current CI, and the actual implementation are authoritative.
- Do not claim a capability is complete unless the relevant UI/service/runtime path and required automated evidence are complete.

## Permanent patch delivery contract
When the user asks for wording equivalent to **"نسخة"**, **"آخر نسخة"**, **"patch"**, **"باتش"**, **"آخر باتش"**, **"hotfix"**, or asks for the latest installable changes without explicitly requesting a full/offline deployment package, the default deliverable is a **small GitHub-backed patch ZIP**, matching the established AIMWWeb patch format. Do not default to a ZIP containing the published `app/` payload.

Required behavior on every such request:
1. Reconstruct the latest `main` state and identify the newest completed/merged product state.
2. Verify the relevant GitHub CI evidence. Never silently pin an unmerged product PR as the user's production patch.
3. Prefer the permanent `Package AIMWWeb GitHub Patch` GitHub Actions artifact.
4. Download the GitHub Actions artifact and hand the actual ZIP file to the user directly.
5. Report the application version and exact pinned GitHub source commit.
6. The patch must download that exact pinned commit from GitHub on the target server, build/publish it before IIS downtime, then deploy it safely.

### Required patch ZIP shape
The delivered patch archive must contain exactly the operational shape used by the accepted AIMWWeb hotfix bundles:
- `Apply-AIMWWeb-Patch.ps1` — administrator PowerShell patcher pinned to an exact GitHub commit.
- `Patch.cmd` — right-click / Run as administrator entry point.
- `manifest.json` — repository, version, exact commit, validation/delivery metadata, and default IIS settings.
- `README.txt` — concise apply/requirements/rollback instructions.
- `SHA256SUMS.txt` — SHA-256 checksums for the other patch files.

### Patch runtime behavior
The patcher must:
- download the exact pinned source commit directly from GitHub;
- require .NET 8 SDK and build/publish **before** stopping IIS;
- use the established defaults unless current deployment authority says otherwise: IIS site `AIMWWeb`, app pool `AIMWWeb`, physical path `C:\inetpub\AIMWWeb`, port `8088`;
- stop/start the IIS site and app pool safely;
- create a rollback backup under `C:\ProgramData\AIMWWeb\Backups`;
- preserve `Data`, `Logs`, `Screenshots`, `Backups`, `Exports`, and `Temp`;
- preserve `appsettings.Production.json` and `appsettings.Local.json` when present;
- verify at least `/health/live` and `/welcome` after deployment;
- write a patch log under `C:\ProgramData\AIMWWeb\Logs`;
- automatically attempt rollback if deployment or verification fails;
- never delete runtime/user data merely to make the patch succeed.

### Patch artifact naming
Use:
`AIMWWeb-Patch-<Version>-<ShortCommit>`

The exact full source commit must be recorded in `manifest.json` and embedded in the PowerShell patcher.

## Full/offline update package exception
Only when the user explicitly asks for a **full package**, **offline package**, **full installable update**, or equivalent, use the larger `Package AIMWWeb Update` artifact containing:
- `app/`
- `Install-Update.ps1`
- `README_AR.txt`
- `VERSION.txt`

Do not substitute the full package for the default patch request.

## UI-to-service work
For normal product implementation, continue closing one dependency-safe vertical slice at a time from the user-visible UI through authorization/ownership/entitlement, application/service/domain behavior, persistence or external WordPress/AI boundary, refreshed UI state, and automated browser evidence. Prefer dead/no-op controls and unverified WordPress mutations before cosmetic work.