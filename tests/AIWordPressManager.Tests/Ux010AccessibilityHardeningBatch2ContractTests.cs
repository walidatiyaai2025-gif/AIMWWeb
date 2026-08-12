using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningBatch2ContractTests
{
    [Fact]
    public void Batch_2_audit_guards_extended_aria_state_and_value_semantics()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardeningBatch2.cs");

        foreach (var token in new[]
        {
            "invalid aria-current value",
            "invalid aria-haspopup value",
            "invalid aria-live value",
            "invalid aria-orientation value",
            "invalid aria-sort value",
            "invalid aria-autocomplete value",
            "role=heading has invalid aria-level",
            "input[type=image] missing alt text",
            "invalid aria-disabled value",
            "aria-valuemin must not exceed aria-valuemax",
            "aria-valuenow is below aria-valuemin",
            "aria-valuenow exceeds aria-valuemax"
        }) audit.Should().Contain(token);
    }

    [Fact]
    public void Batch_2_browser_tests_cover_public_and_authenticated_route_catalogs()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningBatch2RegressionTests.cs");

        tests.Should().Contain("UxRouteCatalog.PublicRoutes");
        tests.Should().Contain("UxRouteCatalog.AuthenticatedRoutes");
        tests.Should().Contain("Public_routes_pass_accessibility_hardening_batch_2");
        tests.Should().Contain("Authenticated_routes_pass_accessibility_hardening_batch_2");
        tests.Should().Contain("UxAccessibilityHardeningBatch2.IssuesAsync");
        tests.Should().Contain("#main-content");
        tests.Should().Contain("/login");
        tests.Should().Contain("/setup");
    }

    [Fact]
    public void Batch_2_manifest_contains_exactly_ten_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_20_TASKS.md");
        var completed = manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal));

        completed.Should().Be(10);
        manifest.Should().Contain("UX010-HARD-011");
        manifest.Should().Contain("UX010-HARD-020");
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
