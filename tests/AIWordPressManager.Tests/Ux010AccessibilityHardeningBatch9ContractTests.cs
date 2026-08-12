using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningBatch9ContractTests
{
    [Fact]
    public void Batch_9_audit_guards_flow_position_level_and_deprecated_aria_metadata()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardeningBatch9.cs");
        foreach (var token in new[]
        {
            "aria-flowto references missing id",
            "aria-posinset must be a positive integer",
            "aria-setsize must be -1 or a positive integer",
            "aria-posinset exceeds aria-setsize",
            "aria-level must be a positive integer",
            "aria-colindextext must not be empty",
            "aria-rowindextext must not be empty",
            "aria-braillelabel must not be empty",
            "aria-brailleroledescription must not be empty",
            "deprecated aria-dropeffect must not be used",
            "deprecated aria-grabbed must not be used"
        }) audit.Should().Contain(token);
    }

    [Fact]
    public void Batch_9_browser_tests_cover_public_and_authenticated_routes()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningBatch9RegressionTests.cs");
        tests.Should().Contain("UxRouteCatalog.PublicRoutes");
        tests.Should().Contain("UxRouteCatalog.AuthenticatedRoutes");
        tests.Should().Contain("Public_routes_pass_accessibility_hardening_batch_9");
        tests.Should().Contain("Authenticated_routes_pass_accessibility_hardening_batch_9");
    }

    [Fact]
    public void Batch_9_manifest_contains_exactly_ten_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_90_TASKS.md");
        manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal)).Should().Be(10);
        manifest.Should().Contain("UX010-HARD-081");
        manifest.Should().Contain("UX010-HARD-090");
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
