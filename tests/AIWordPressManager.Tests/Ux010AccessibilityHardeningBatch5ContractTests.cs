using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningBatch5ContractTests
{
    [Fact]
    public void Batch_5_audit_guards_native_association_and_landmark_semantics()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardeningBatch5.cs");

        foreach (var token in new[]
        {
            "label for references missing id",
            "label for target is not labelable",
            "output for must reference at least one id",
            "output for references missing id",
            "input list must reference a datalist",
            "form-associated control references missing form",
            "table cell headers must reference at least one th id",
            "table cell headers references missing th id",
            "usemap references missing map",
            "image-map area link missing alt text",
            "visible details element missing direct summary",
            "multiple visible nav landmarks require accessible names",
            "multiple visible aside landmarks require accessible names"
        }) audit.Should().Contain(token);
    }

    [Fact]
    public void Batch_5_browser_tests_cover_public_and_authenticated_route_catalogs()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningBatch5RegressionTests.cs");

        tests.Should().Contain("UxRouteCatalog.PublicRoutes");
        tests.Should().Contain("UxRouteCatalog.AuthenticatedRoutes");
        tests.Should().Contain("Public_routes_pass_accessibility_hardening_batch_5");
        tests.Should().Contain("Authenticated_routes_pass_accessibility_hardening_batch_5");
        tests.Should().Contain("UxAccessibilityHardeningBatch5.IssuesAsync");
        tests.Should().Contain("#main-content");
        tests.Should().Contain("/login");
        tests.Should().Contain("/setup");
    }

    [Fact]
    public void Batch_5_manifest_contains_exactly_ten_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_50_TASKS.md");
        var completed = manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal));

        completed.Should().Be(10);
        manifest.Should().Contain("UX010-HARD-041");
        manifest.Should().Contain("UX010-HARD-050");
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
