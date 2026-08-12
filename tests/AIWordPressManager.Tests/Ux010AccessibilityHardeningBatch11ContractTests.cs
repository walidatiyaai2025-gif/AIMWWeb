using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningBatch11ContractTests
{
    [Fact]
    public void Batch_11_audit_guards_dom_focus_nesting_and_aria_ownership()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardeningBatch11.cs");
        foreach (var token in new[]
        {
            "duplicate id attribute value",
            "visible element uses positive tabindex",
            "button contains nested interactive content",
            "link contains nested interactive content",
            "visible role=tab is not owned by a tablist",
            "visible role=option is not owned by a listbox",
            "is not owned by a menu or menubar",
            "visible role=treeitem is not owned by a tree",
            "visible role=row is not owned by a table, grid, treegrid, or rowgroup",
            "is not owned by a row"
        }) audit.Should().Contain(token);
    }

    [Fact]
    public void Batch_11_browser_tests_cover_public_and_authenticated_routes()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningBatch11RegressionTests.cs");
        tests.Should().Contain("UxRouteCatalog.PublicRoutes");
        tests.Should().Contain("UxRouteCatalog.AuthenticatedRoutes");
        tests.Should().Contain("Public_routes_pass_accessibility_hardening_batch_11");
        tests.Should().Contain("Authenticated_routes_pass_accessibility_hardening_batch_11");
    }

    [Fact]
    public void Batch_11_manifest_contains_exactly_ten_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_110_TASKS.md");
        manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal)).Should().Be(10);
        manifest.Should().Contain("UX010-HARD-101");
        manifest.Should().Contain("UX010-HARD-110");
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
