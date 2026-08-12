using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningBatch4ContractTests
{
    [Fact]
    public void Batch_4_audit_guards_hidden_live_validation_and_string_semantics()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardeningBatch4.cs");

        foreach (var token in new[]
        {
            "invalid aria-hidden value",
            "invalid aria-atomic value",
            "invalid aria-invalid value",
            "invalid aria-relevant value",
            "invalid aria-relevant token",
            "aria-valuetext must not be empty",
            "aria-roledescription must not be empty",
            "aria-description must not be empty",
            "aria-placeholder must not be empty",
            "aria-keyshortcuts must not be empty",
            "aria-label must not be empty when present"
        }) audit.Should().Contain(token);
    }

    [Fact]
    public void Batch_4_browser_tests_cover_public_and_authenticated_route_catalogs()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningBatch4RegressionTests.cs");

        tests.Should().Contain("UxRouteCatalog.PublicRoutes");
        tests.Should().Contain("UxRouteCatalog.AuthenticatedRoutes");
        tests.Should().Contain("Public_routes_pass_accessibility_hardening_batch_4");
        tests.Should().Contain("Authenticated_routes_pass_accessibility_hardening_batch_4");
        tests.Should().Contain("UxAccessibilityHardeningBatch4.IssuesAsync");
        tests.Should().Contain("#main-content");
        tests.Should().Contain("/login");
        tests.Should().Contain("/setup");
    }

    [Fact]
    public void Batch_4_manifest_contains_exactly_ten_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_40_TASKS.md");
        var completed = manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal));

        completed.Should().Be(10);
        manifest.Should().Contain("UX010-HARD-031");
        manifest.Should().Contain("UX010-HARD-040");
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
