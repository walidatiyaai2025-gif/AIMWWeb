using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class UxRegressionGateContractTests
{
    [Fact]
    public void Workflow_installs_chromium_runs_browser_gate_and_uploads_evidence()
    {
        var workflow = ReadRepositoryFile(".github/workflows/ux-regression.yml");
        workflow.Should().Contain("name: UX Regression Gate");
        workflow.Should().Contain("pull_request:");
        workflow.Should().Contain("branches: [ main ]");
        workflow.Should().Contain("playwright.ps1 install --with-deps chromium");
        workflow.Should().Contain("AIWordPressManager.UxTests.csproj");
        workflow.Should().Contain("ux-regression-evidence");
        workflow.Should().Contain("artifacts/ux-regression/**");
        workflow.Should().Contain("retention-days: 30");
        workflow.Should().Contain("if: always()");
    }

    [Fact]
    public void Browser_project_uses_centrally_pinned_playwright_and_stays_outside_core_solution()
    {
        var project = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AIWordPressManager.UxTests.csproj");
        var packages = ReadRepositoryFile("Directory.Packages.props");
        var solution = ReadRepositoryFile("AIWordPressManager.Web.sln");
        project.Should().Contain("<PackageReference Include=\"Microsoft.Playwright\" />");
        project.Should().Contain("<IsTestProject>true</IsTestProject>");
        packages.Should().Contain("PackageVersion Include=\"Microsoft.Playwright\"");
        solution.Should().NotContain("AIWordPressManager.UxTests");
    }

    [Fact]
    public void Browser_host_is_loopback_isolated_and_uses_temporary_sqlite_plus_seeded_admin()
    {
        var host = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxTestHost.cs");
        host.Should().Contain("127.0.0.1");
        host.Should().Contain("ReserveTcpPort");
        host.Should().Contain("aiwm-ux-");
        host.Should().Contain("Database__SetupComplete");
        host.Should().Contain("Database__Provider");
        host.Should().Contain("SQLite");
        host.Should().Contain("Pooling=False");
        host.Should().Contain("XDG_DATA_HOME");
        host.Should().Contain("/health/live");
        host.Should().Contain("Admin@123");
        host.Should().Contain("StorageStateAsync");
        host.Should().Contain("web-host.log");
    }

    [Fact]
    public void Route_catalog_contains_public_operational_and_high_risk_workspaces()
    {
        var catalog = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxRouteCatalog.cs");
        foreach (var route in new[]
        {
            "/welcome", "/login", "/", "/sites", "/ai-center", "/module/ai-usage",
            "/settings/ai-prompts", "/approvals", "/account/profile", "/account/email-settings",
            "/system-health", "/about-build"
        }) catalog.Should().Contain($"\"{route}\"");
        catalog.Should().Contain("HighRisk: true");
        catalog.Should().Contain("AdminOnly: true");
    }

    [Fact]
    public void Viewport_catalog_guards_phone_tablet_and_desktop_breakpoints()
    {
        var catalog = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxRouteCatalog.cs");
        catalog.Should().Contain("new(\"phone\", 390, 844)");
        catalog.Should().Contain("new(\"tablet\", 768, 1024)");
        catalog.Should().Contain("new(\"desktop\", 1440, 900)");
    }

    [Fact]
    public void Accessibility_audit_guards_core_browser_semantics()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAudit.cs");
        audit.Should().Contain("html element must declare lang");
        audit.Should().Contain("html element must declare ltr or rtl direction");
        audit.Should().Contain("duplicate id:");
        audit.Should().Contain("image missing alt:");
        audit.Should().Contain("form control missing accessible label:");
        audit.Should().Contain("interactive control missing accessible name:");
        audit.Should().Contain("positive tabindex is not allowed:");
        audit.Should().Contain("nested interactive controls detected");
        audit.Should().Contain("expected one visible main landmark");
        audit.Should().Contain("expected one visible h1");
        audit.Should().Contain("#main-content");
        audit.Should().Contain("runtime direction metadata missing");
    }

    [Fact]
    public void Material_visual_gate_guards_overflow_clipping_hierarchy_and_metadata()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAudit.cs");
        audit.Should().Contain("HorizontalOverflow.Should().BeLessThanOrEqualTo(1");
        audit.Should().Contain("MainWidth.Should().BeGreaterThan(0");
        audit.Should().Contain("H1Count.Should().Be(1");
        audit.Should().Contain("ClippedSurfaces.Should().Be(0");
        audit.Should().Contain("app-toolbar,.app-card,.app-section,.panel");
        audit.Should().Contain("FullPage = true");
        audit.Should().Contain("SHA256.HashDataAsync");
    }

    [Fact]
    public void Screenshot_registry_is_opt_in_and_approved_hashes_become_hard_failures()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAudit.cs");
        var strategy = ReadRepositoryFile("docs/UX_010_VISUAL_BASELINE_STRATEGY.md");
        var registry = ReadRepositoryFile("tests/AIWordPressManager.UxTests/Baselines/approved-screenshot-sha256.json");
        audit.Should().Contain("approved-screenshot-sha256.json");
        audit.Should().Contain("approvedHash");
        strategy.Should().Contain("approved SHA-256 baseline");
        strategy.Should().Contain("Never regenerate or replace approved screenshot hashes only to make CI green");
        registry.Trim().Should().Be("{}");
    }

    [Fact]
    public void Browser_suite_covers_routes_breakpoints_rtl_keyboard_and_evidence()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/BrowserUxRegressionTests.cs");
        tests.Should().Contain("Public_routes_render_without_server_or_browser_failure");
        tests.Should().Contain("Authenticated_routes_render_and_pass_accessibility_smoke");
        tests.Should().Contain("High_risk_pages_hold_visual_contract_at_key_breakpoints");
        tests.Should().Contain("Selected_high_risk_pages_preserve_material_contract_in_arabic_rtl");
        tests.Should().Contain("Keyboard_focus_enters_the_authenticated_application");
        tests.Should().Contain("SaveVisualEvidenceAsync");
        tests.Should().Contain("aiwp-language', 'ar'");
    }

    [Fact]
    public void Manifest_contains_exactly_one_hundred_completed_ux010_tasks_and_compatibility_boundary()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_100_TASKS.md");
        var completed = manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-", StringComparison.Ordinal));
        completed.Should().Be(100);
        manifest.Should().Contain("UX010-001");
        manifest.Should().Contain("UX010-100");
        manifest.Should().Contain("without changing production authentication, tenant ownership, database schema, API contracts, AI routing, approval behavior, WordPress execution, or persistence semantics");
    }

    private static string ReadRepositoryFile(string relativePath)
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            var solution = Path.Combine(current.FullName, "AIWordPressManager.Web.sln");
            if (File.Exists(solution)) return File.ReadAllText(Path.Combine(current.FullName, relativePath));
            current = current.Parent;
        }
        throw new DirectoryNotFoundException("Could not locate repository root from test output.");
    }
}
