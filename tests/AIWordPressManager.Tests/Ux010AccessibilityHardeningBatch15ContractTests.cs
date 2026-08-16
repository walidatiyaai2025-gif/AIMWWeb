using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningBatch15ContractTests
{
    [Fact]
    public void Batch_15_audit_guards_required_accessible_names()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardeningBatch15.cs");
        foreach (var token in new[]
        {
            "visible role=button missing accessible name",
            "visible role=radio missing accessible name",
            "visible role=switch missing accessible name",
            "visible role=grid missing accessible name",
            "visible role=treegrid missing accessible name",
            "aria-labelledby",
            "contentAccessibleName",
            "authorAccessibleName"
        }) audit.Should().Contain(token);
    }

    [Fact]
    public void Batch_15_browser_tests_cover_public_and_authenticated_routes()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningBatch15RegressionTests.cs");
        tests.Should().Contain("UxRouteCatalog.PublicRoutes");
        tests.Should().Contain("UxRouteCatalog.AuthenticatedRoutes");
        tests.Should().Contain("Public_routes_pass_accessibility_hardening_batch_15");
        tests.Should().Contain("Authenticated_routes_pass_accessibility_hardening_batch_15");
    }

    [Fact]
    public void Batch_15_manifest_contains_exactly_five_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_130_TASKS.md");
        manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal)).Should().Be(5);
        manifest.Should().Contain("UX010-HARD-126");
        manifest.Should().Contain("UX010-HARD-130");
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
