using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningBatch10ContractTests
{
    [Fact]
    public void Batch_10_audit_guards_dom_references_keyboard_metadata_and_editable_targets()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardeningBatch10.cs");
        foreach (var token in new[]
        {
            "id attribute must not be empty",
            "id attribute must not contain whitespace",
            "same-page fragment references missing id",
            "tabindex must be an integer",
            "multiple visible autofocus targets detected",
            "autofocus target must not be disabled or hidden",
            "accesskey must contain one-character tokens",
            "duplicate visible accesskey token",
            "visible contenteditable region missing accessible name",
            "visible inline-click target is not keyboard operable"
        }) audit.Should().Contain(token);
    }

    [Fact]
    public void Batch_10_browser_tests_cover_public_and_authenticated_routes()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningBatch10RegressionTests.cs");
        tests.Should().Contain("UxRouteCatalog.PublicRoutes");
        tests.Should().Contain("UxRouteCatalog.AuthenticatedRoutes");
        tests.Should().Contain("Public_routes_pass_accessibility_hardening_batch_10");
        tests.Should().Contain("Authenticated_routes_pass_accessibility_hardening_batch_10");
    }

    [Fact]
    public void Batch_10_manifest_contains_exactly_ten_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_100_TASKS.md");
        manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal)).Should().Be(10);
        manifest.Should().Contain("UX010-HARD-091");
        manifest.Should().Contain("UX010-HARD-100");
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
