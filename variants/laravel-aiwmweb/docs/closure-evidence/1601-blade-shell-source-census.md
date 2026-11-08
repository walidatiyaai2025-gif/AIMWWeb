# Issue #1601 — Laravel Blade shell source census (source inspection only)

Source inventory baseline: `main@de4b08ff8eac483b87fc751da177edd3bc7e4b40` (2026-11-08). Incremental fixes tracked separately: #1648 Site Settings merged, #1650 AI Provider Settings merged, email shell merged through PR #1653, and Security Audit being reconciled in PR #1652. This source census remains **not browser verification**.
Scope: `variants/laravel-aiwmweb/backend/resources/views/**`. **All 29 discovered Blade templates** were inspected for `@vite`, a visible dashboard shell, sidebar/topbar markup, and locale/direction declarations. This is a **static source census**, NOT a browser route crawl, visual acceptance, production CSS-load result, or evidence that a given route is reachable to a particular role. Laravel React SPA pages may render their shell client-side.

## Classification and evidence

| Blade template | Source observation | Classification / follow-up |
| --- | --- | --- |
| `app.blade.php` | Vite React entry point; no Blade sidebar | SPA client-shell; browser verify |
| `console.blade.php` | Standalone demo UI; no Vite | Determine whether reachable in production; avoid demo route |
| `operations-maintenance.blade.php` | Vite; no Blade sidebar | Determine React ownership and visual state |
| `site-operations-maintenance.blade.php` | Vite; no Blade sidebar | Determine React ownership and visual state |
| `setup.blade.php` | Standalone owner activation; no Vite | Installer/public workflow; distinct by design; verify styled setup |
| `welcome.blade.php` | Public welcome page; Vite | Public landing; intentionally no authenticated shell |
| `admin/application-sessions.blade.php` | Initial census: standalone inline CSS with no Vite/sidebar | **Current PR candidate**: shared read-only authenticated tenant shell, locale-aware AR/EN topbar/nav, Vite CSS and mobile layout; browser/host unverified |
| `ai/choose-tenant.blade.php` | Standalone inline CSS, no Vite | Tenant selection; route/visual verification needed |
| `ai/prompt-templates.blade.php` | `data-dashboard-shell`, Vite-conditional app stylesheet, own inline shell CSS | Existing implemented shell; verify compiled CSS and AR/EN browser (#1512) |
| `ai/provider-form.blade.php` | Partial form, no document markup | N/A; included in provider settings |
| `ai/provider-settings.blade.php` | On inventory base: fixed `lang="ar" dir="rtl"`, inline CSS, no Vite/sidebar | **Merged in #1650**, published in signed GitHub v1.0.735; host and browser unverified |
| `ai/welcome.blade.php` | Standalone welcome UI, no Vite | Public/entry context; verify route intent |
| `auth/login.blade.php` | Standalone English login UI, no Vite | Public auth; verify bilingual expectation, not required authenticated sidebar |
| `auth/register.blade.php` | Standalone English registration UI, no Vite | Public auth; verify bilingual expectation |
| `billing/admin-support.blade.php` | Initial census: English-only standalone admin read-only view with no Vite/sidebar | **Current PR candidate**: shared platform-admin read-only shell, AR/EN localized safety copy, Vite CSS; guest/non-admin denial retained; browser/host unverified |
| `billing/subscription-plans-admin.blade.php` | Standalone inline CSS, locale-aware document, no Vite/sidebar | **Review authenticated dashboard parity** |
| `operations/site-operation-details.blade.php` | Vite, no Blade sidebar | Determine React ownership; browser verify |
| `operations/site-operation-details-load-error.blade.php` | Vite, no Blade sidebar | Error/fallback route; verify accessibility/layout |
| `platform/about-build.blade.php` | Vite, no Blade sidebar | Determine React ownership; browser verify |
| `platform/access-denied.blade.php` | Standalone inline CSS, no Vite/sidebar | Forbidden page; verify authorized exit navigation |
| `platform/error.blade.php` | Vite, no Blade sidebar | Error/fallback route; verify safe layout |
| `platform/system-updates.blade.php` | Standalone light-mode inline CSS, no Vite/sidebar | **Review authenticated platform-admin dashboard parity**; preserve signed update security |
| `reports/approvals-export.blade.php` | Standalone inline CSS, no Vite/sidebar | **Review authenticated dashboard parity** |
| `security/audit.blade.php` | On inventory base: English-only standalone read-only platform audit view without Vite/sidebar | **Current #1601 security audit shell candidate**: AR/EN sidebar/topbar/Vite with original platform-admin/read-only guards; browser/host acceptance unverified |
| `seo/manager.blade.php` | Vite, no Blade sidebar | Determine React ownership; browser verify |
| `seo/workspace.blade.php` | Vite, no Blade sidebar | Determine React ownership; browser verify |
| `sites/email-diagnostics.blade.php` | On inventory base: standalone light/dark Canvas CSS, no Vite/sidebar | **Current #1601 email-shell candidate**: uses shared site-email shell/sidebar/topbar/Vite and AR/EN; live browser/host unverified |
| `sites/email-settings.blade.php` | On inventory base: standalone light/dark Canvas CSS, no Vite/sidebar | **Current #1601 email-shell candidate**: shared site-email shell/sidebar/topbar/Vite and keyboard-scroll recipients; live browser/host unverified |
| `sites/settings.blade.php` | Common `app-shell`/sidebar and @vite app stylesheet | #1648 merged; browser and production asset verification pending |

## Verification still required before closing #1601

1. Map each Blade view to its actual Laravel GET route/controller, middleware, permissions, tenant/site scoping and user-visible navigation; account for React SPA client-rendered routes separately.
2. For each authenticated route, capture AR/EN and desktop/mobile screenshots, computed layout direction, CSS/JS response status and navigational outcomes from a tagged candidate build.
3. Remediate the **remaining standalone authenticated views** through shared components, without replacing CSRF, RBAC, encrypted secret storage, signed updates, site mail, billing or operational guards.
4. Run exact-head PHPUnit/React/CI, fresh Setup ZIP and signed Upgrade ZIP acceptance. Obtain authenticated on-host cPanel apply/backup/health and installed SHA receipt before saying *deployed*.

**No production browser, customer data, WordPress mutation, secrets, installer state or hosted update was accessed in this source audit.** Source observations must not be upgraded to visual/production PASS without separate evidence.
