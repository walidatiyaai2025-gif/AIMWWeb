# AI WordPress Manager 152 — Blazor Server Users & Roles Manager

Web-only ASP.NET Core 8 Blazor Server application using EF Core and local SQLite.

## Added in version 152
- WordPress users list with search, role filter, and pagination.
- Create and edit users.
- Assign standard WordPress roles.
- Disable a user by removing roles.
- Delete a user and reassign content to the currently authenticated account.
- Protection against disabling or deleting the active API account.
- Full Arabic/English localization and RTL/LTR support.

## Build
```powershell
powershell -ExecutionPolicy Bypass -File .\Build\Repair-And-Build.ps1
```


## Version 152 - SEO Manager
- Local SEO analysis for synchronized posts and pages.
- Score from 0 to 100 with bilingual issue descriptions.
- Detects missing/short/long titles, missing descriptions, thin content, missing headings, internal links, missing image alt text, and missing slugs.
- Filters by post/page and links directly to the content editor for fixes.
