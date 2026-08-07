# AI WordPress Manager Setup & Recovery Tool

Main entry points:

- `Install-First-Time.bat` — easiest interactive launcher on Windows.
- `Setup-Tool.ps1` — full setup, recovery, Git, build, test, and run tool.
- `Install-First-Time.ps1` — compatibility wrapper for older shortcuts/scripts.

## Interactive mode

Run:

```powershell
.\Install-First-Time.bat
```

The menu provides:

1. First installation or update existing installation.
2. Pull latest configured GitHub branch only.
3. Build application only.
4. Run tests only.
5. Run application only.
6. Diagnose environment/repository.
7. Push already committed local changes to GitHub.

## First installation

The tool asks for the installation directory. No local installation path is hard-coded.

If the directory does not exist, the configured branch is cloned. If an empty directory exists, it can also be used. A non-empty directory that is not a Git repository is rejected instead of being overwritten.

## Existing installation/update

The tool:

1. Validates Git and .NET 8 SDK.
2. Checks repository ownership and can add `safe.directory` after approval.
3. Verifies the `origin` remote.
4. Detects local uncommitted changes.
5. Offers to stash those changes, abort, or explicitly discard them.
6. Fetches the latest configured branch.
7. Switches branch only when required.
8. Resets the working branch to the verified remote branch.
9. Detects the tracked solution automatically.
10. Cleans tracked project `bin` and `obj` folders only.
11. Restores NuGet packages.
12. Offers NuGet cache repair and retry if restore fails.
13. Builds the application.
14. Optionally starts the Web project.

## Diagnostics and logging

Every run writes a detailed log under the current Windows temporary directory:

```text
%TEMP%\AIWordPressManager-Setup\setup-YYYYMMDD-HHMMSS.log
```

On failure the console displays:

- the user-facing error,
- diagnostic log path,
- last native command,
- native process exit code.

## Command examples

Install/update without starting the application:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Setup-Tool.ps1 `
  -Mode InstallOrUpdate `
  -InstallPath "<your repository path>" `
  -Branch main `
  -SkipStart
```

Diagnose a repository:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Setup-Tool.ps1 `
  -Mode Diagnose `
  -InstallPath "<your repository path>"
```

Build only:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Setup-Tool.ps1 `
  -Mode Build `
  -InstallPath "<your repository path>" `
  -Configuration Release
```

Pull only:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Setup-Tool.ps1 `
  -Mode Pull `
  -InstallPath "<your repository path>" `
  -Branch main
```

Push committed changes only:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Setup-Tool.ps1 `
  -Mode Push `
  -InstallPath "<your repository path>" `
  -Branch main
```

The Push mode does not create commits. Uncommitted files are never silently uploaded.

## Safety rules

- No installation path is hard-coded.
- Existing non-Git directories are not overwritten.
- Uncommitted changes are not deleted unless the user explicitly selects discard.
- Git stderr text is not treated as failure when Git returns exit code 0.
- `git clean` is not used during normal update/build operations.
- Running `dotnet` processes are never killed without explicit approval.
- The tool does not auto-commit source changes.
- Push requires explicit confirmation.
