using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningBatch17ContractTests
{
    [Fact]
    public void Batch_17_audit_guards_required_heading_menu_and_combobox_semantics()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardeningBatch17.cs");
        foreach (var token in new[]
        {
            "visible role=heading missing accessible name",
            "role=menuitemcheckbox requires aria-checked: true|false|mixed",
            "role=menuitemradio requires aria-checked: true|false",
            "role=combobox requires resolving aria-controls popup",
            "role=combobox aria-haspopup must match controlled popup role",
            "supportedPopupRoles",
            "aria-labelledby"
        }) audit.Should().Contain(token);
    }

    [Fact]
    public void Batch_17_browser_tests_cover_public_and_authenticated_routes()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningBatch17RegressionTests.cs");
        tests.Should().Contain("UxRouteCatalog.PublicRoutes");
        tests.Should().Contain("UxRouteCatalog.AuthenticatedRoutes");
        tests.Should().Contain("Public_routes_pass_accessibility_hardening_batch_17");
        tests.Should().Contain("Authenticated_routes_pass_accessibility_hardening_batch_17");
    }

    [Fact]
    public void Batch_17_manifest_contains_exactly_five_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_140_TASKS.md");
        manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal)).Should().Be(5);
        manifest.Should().Contain("UX010-HARD-136");
        manifest.Should().Contain("UX010-HARD-140");
        manifest.Should().Contain("No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed");
    }

    private static string ReadRepositoryFile(string relativePath)
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "AIWordPressManager.Web.sln")))
                return File.ReadAllText(Path.Combine(current.FullName, relativePath));
            current = current.Parent;
        }
        throw new DirectoryNotFoundException("Could not locate repository root from test output.");
    }
}
