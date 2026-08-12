using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningBatch8ContractTests
{
    [Fact]
    public void Batch_8_audit_guards_native_group_table_and_output_semantics()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardeningBatch8.cs");
        foreach (var token in new[]
        {
            "visible fieldset with controls missing legend or accessible name",
            "optgroup missing non-empty label",
            "table caption must not be empty",
            "invalid th scope value",
            "visible meter missing accessible name",
            "visible progress missing accessible name",
            "visible output missing accessible name",
            "visible summary missing accessible name",
            "multiple visible forms require accessible names",
            "visible native radio group has no labeled option"
        }) audit.Should().Contain(token);
    }

    [Fact]
    public void Batch_8_browser_tests_cover_public_and_authenticated_routes()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningBatch8RegressionTests.cs");
        tests.Should().Contain("UxRouteCatalog.PublicRoutes");
        tests.Should().Contain("UxRouteCatalog.AuthenticatedRoutes");
        tests.Should().Contain("Public_routes_pass_accessibility_hardening_batch_8");
        tests.Should().Contain("Authenticated_routes_pass_accessibility_hardening_batch_8");
    }

    [Fact]
    public void Batch_8_manifest_contains_exactly_ten_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_80_TASKS.md");
        manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal)).Should().Be(10);
        manifest.Should().Contain("UX010-HARD-071");
        manifest.Should().Contain("UX010-HARD-080");
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
