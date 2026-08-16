using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningBatch13ContractTests
{
    [Fact]
    public void Batch_13_audit_guards_remaining_widget_state_name_and_range_contracts()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardeningBatch13.cs");
        foreach (var token in new[]
        {
            "visible role=checkbox missing accessible name",
            "role=checkbox requires aria-checked true, false, or mixed",
            "visible role=meter missing accessible name",
            "visible role=meter requires numeric aria-valuenow",
            "visible role=scrollbar requires resolving aria-controls",
            "visible role=scrollbar requires numeric aria-valuenow",
            "focusable role=separator requires numeric aria-valuenow",
            "visible role=searchbox missing accessible name"
        }) audit.Should().Contain(token);
    }

    [Fact]
    public void Batch_13_browser_tests_cover_public_and_authenticated_routes()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningBatch13RegressionTests.cs");
        tests.Should().Contain("UxRouteCatalog.PublicRoutes");
        tests.Should().Contain("UxRouteCatalog.AuthenticatedRoutes");
        tests.Should().Contain("Public_routes_pass_accessibility_hardening_batch_13");
        tests.Should().Contain("Authenticated_routes_pass_accessibility_hardening_batch_13");
    }

    [Fact]
    public void Batch_13_manifest_contains_exactly_five_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_120_TASKS.md");
        manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal)).Should().Be(5);
        manifest.Should().Contain("UX010-HARD-116");
        manifest.Should().Contain("UX010-HARD-120");
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
