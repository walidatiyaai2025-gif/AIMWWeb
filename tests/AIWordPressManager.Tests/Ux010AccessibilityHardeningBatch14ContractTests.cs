using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningBatch14ContractTests
{
    [Fact]
    public void Batch_14_audit_guards_required_accessible_names()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardeningBatch14.cs");
        foreach (var token in new[]
        {
            "visible role=spinbutton missing accessible name",
            "visible role=textbox missing accessible name",
            "visible role=progressbar missing accessible name",
            "visible role=listbox missing accessible name",
            "visible role=tree missing accessible name",
            "aria-labelledby",
            "placeholder"
        }) audit.Should().Contain(token);
    }

    [Fact]
    public void Batch_14_browser_tests_cover_public_and_authenticated_routes()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningBatch14RegressionTests.cs");
        tests.Should().Contain("UxRouteCatalog.PublicRoutes");
        tests.Should().Contain("UxRouteCatalog.AuthenticatedRoutes");
        tests.Should().Contain("Public_routes_pass_accessibility_hardening_batch_14");
        tests.Should().Contain("Authenticated_routes_pass_accessibility_hardening_batch_14");
    }

    [Fact]
    public void Batch_14_manifest_contains_exactly_five_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_125_TASKS.md");
        manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal)).Should().Be(5);
        manifest.Should().Contain("UX010-HARD-121");
        manifest.Should().Contain("UX010-HARD-125");
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
