using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningBatch16ContractTests
{
    [Fact]
    public void Batch_16_audit_guards_required_accessible_names()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardeningBatch16.cs");
        foreach (var token in new[]
        {
            "visible role=columnheader missing accessible name",
            "visible role=rowheader missing accessible name",
            "visible role=tabpanel missing accessible name",
            "visible role=tooltip missing accessible name",
            "visible role=table missing accessible name",
            "aria-labelledby",
            "contentAccessibleName",
            "authorAccessibleName"
        }) audit.Should().Contain(token);
    }

    [Fact]
    public void Batch_16_browser_tests_cover_public_and_authenticated_routes()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningBatch16RegressionTests.cs");
        tests.Should().Contain("UxRouteCatalog.PublicRoutes");
        tests.Should().Contain("UxRouteCatalog.AuthenticatedRoutes");
        tests.Should().Contain("Public_routes_pass_accessibility_hardening_batch_16");
        tests.Should().Contain("Authenticated_routes_pass_accessibility_hardening_batch_16");
    }

    [Fact]
    public void Batch_16_manifest_contains_exactly_five_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_135_TASKS.md");
        manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal)).Should().Be(5);
        manifest.Should().Contain("UX010-HARD-131");
        manifest.Should().Contain("UX010-HARD-135");
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
