AIMWWeb GitHub Patch
====================

Purpose
-------
Updates the installed AIMWWeb instance to the exact validated GitHub commit pinned in this patch.

Validated source
----------------
Repository: https://github.com/walidatiyaai2025-gif/AIMWWeb
Version: __VERSION__
Commit: __SOURCE_COMMIT__

How to apply
------------
1. Extract this folder on the IIS server.
2. Right-click Patch.cmd -> Run as administrator.
3. Wait for PATCH APPLIED SUCCESSFULLY.
4. The browser will open /welcome.

Defaults
--------
IIS Site: AIMWWeb
App Pool: AIMWWeb
Physical path: C:\inetpub\AIMWWeb
Port: 8088

What the patch does
-------------------
- Downloads the exact pinned commit directly from GitHub.
- Restores and publishes the application BEFORE stopping IIS.
- Stops the AIMWWeb site/app pool.
- Backs up current application files under C:\ProgramData\AIMWWeb\Backups\GitHubPatch-<timestamp>.
- Overlays the new published application.
- Preserves Data, Logs, Screenshots, Backups, Exports, Temp.
- Preserves appsettings.Production.json and appsettings.Local.json.
- Starts IIS.
- Verifies /health/live and /welcome.
- Automatically attempts rollback if deployment or verification fails.

Logs
----
C:\ProgramData\AIMWWeb\Logs\github-patch-<timestamp>.log

Requirements
------------
- Run as Administrator.
- .NET 8 SDK installed.
- IIS site/app pool named AIMWWeb unless parameters are overridden.
- Internet access to GitHub.

Custom paths
------------
powershell -ExecutionPolicy Bypass -File .\Apply-AIMWWeb-Patch.ps1 -PhysicalPath "C:\inetpub\AIMWWeb" -Port 8088
