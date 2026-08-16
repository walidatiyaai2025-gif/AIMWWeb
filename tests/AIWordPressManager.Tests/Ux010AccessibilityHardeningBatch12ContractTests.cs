using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningBatch12ContractTests
{
    [Fact]
    public void Batch_12_audit_guards_required_owned_elements_and_busy_state()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardeningBatch12.cs");
        foreach (var token in new[]
        {
            "visible role=tablist is missing an owned tab",
            "visible role=listbox is missing an owned option",
            "is missing an owned menu item",
            "visible role=tree is missing an owned treeitem",
            "is missing an owned row",
            "aria-owns",
            "aria-busy=\"true\""
        }) audit.Should().Contain(token);
    }

    [Fact]
    public void Batch_12_browser_tests_cover_public_and_authenticated_routes()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningBatch12RegressionTests.cs");
        tests.Should().Contain("UxRouteCatalog.PublicRoutes");
        tests.Should().Contain("UxRouteCatalog.AuthenticatedRoutes");
        tests.Should().Contain("Public_routes_pass_accessibility_hardening_batch_12");
        tests.Should().Contain("Authenticated_routes_pass_accessibility_hardening_batch_12");
    }

    [Fact]
    public void Batch_12_manifest_contains_exactly_five_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_115_TASKS.md");
        manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal)).Should().Be(5);
        manifest.Should().Contain("UX010-HARD-111");
        manifest.Should().Contain("UX010-HARD-115");
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
