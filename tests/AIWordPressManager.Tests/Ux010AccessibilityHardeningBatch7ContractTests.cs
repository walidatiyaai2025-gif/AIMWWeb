using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningBatch7ContractTests
{
    [Fact]
    public void Batch_7_audit_guards_landmark_radio_slider_and_tree_semantics()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardeningBatch7.cs");
        foreach (var token in new[]
        {
            "visible role=region missing accessible name",
            "visible role=form missing accessible name",
            "visible role=application missing accessible name",
            "visible role=radiogroup missing accessible name",
            "role=radio requires boolean aria-checked",
            "visible radiogroup has no keyboard entry radio",
            "visible role=slider missing accessible name",
            "role=slider requires numeric aria-valuenow",
            "visible role=treeitem missing accessible name",
            "treeitem with child group requires boolean aria-expanded"
        }) audit.Should().Contain(token);
    }

    [Fact]
    public void Batch_7_browser_tests_cover_public_and_authenticated_route_catalogs()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningBatch7RegressionTests.cs");
        tests.Should().Contain("UxRouteCatalog.PublicRoutes");
        tests.Should().Contain("UxRouteCatalog.AuthenticatedRoutes");
        tests.Should().Contain("Public_routes_pass_accessibility_hardening_batch_7");
        tests.Should().Contain("Authenticated_routes_pass_accessibility_hardening_batch_7");
        tests.Should().Contain("UxAccessibilityHardeningBatch7.IssuesAsync");
    }

    [Fact]
    public void Batch_7_manifest_contains_exactly_ten_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_70_TASKS.md");
        manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal)).Should().Be(10);
        manifest.Should().Contain("UX010-HARD-061");
        manifest.Should().Contain("UX010-HARD-070");
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
