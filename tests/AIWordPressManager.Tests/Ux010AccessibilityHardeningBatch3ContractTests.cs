using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningBatch3ContractTests
{
    [Fact]
    public void Batch_3_audit_guards_boolean_reference_and_grid_semantics()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardeningBatch3.cs");

        foreach (var token in new[]
        {
            "invalid aria-busy value",
            "invalid aria-multiline value",
            "invalid aria-multiselectable value",
            "invalid aria-readonly value",
            "invalid aria-required value",
            "invalid aria-modal value",
            "aria-errormessage references missing id",
            "aria-details references missing id",
            "aria-activedescendant must reference exactly one id",
            "aria-activedescendant references missing id",
            "aria-colindex must be a positive integer",
            "aria-rowindex must be a positive integer",
            "aria-colspan must be a positive integer",
            "aria-rowspan must be a positive integer",
            "aria-colcount must be -1 or a positive integer",
            "aria-rowcount must be -1 or a positive integer"
        }) audit.Should().Contain(token);
    }

    [Fact]
    public void Batch_3_browser_tests_cover_public_and_authenticated_route_catalogs()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningBatch3RegressionTests.cs");

        tests.Should().Contain("UxRouteCatalog.PublicRoutes");
        tests.Should().Contain("UxRouteCatalog.AuthenticatedRoutes");
        tests.Should().Contain("Public_routes_pass_accessibility_hardening_batch_3");
        tests.Should().Contain("Authenticated_routes_pass_accessibility_hardening_batch_3");
        tests.Should().Contain("UxAccessibilityHardeningBatch3.IssuesAsync");
        tests.Should().Contain("#main-content");
        tests.Should().Contain("/login");
        tests.Should().Contain("/setup");
    }

    [Fact]
    public void Batch_3_manifest_contains_exactly_ten_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_30_TASKS.md");
        var completed = manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal));

        completed.Should().Be(10);
        manifest.Should().Contain("UX010-HARD-021");
        manifest.Should().Contain("UX010-HARD-030");
        manifest.Should().Contain("No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed");
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
