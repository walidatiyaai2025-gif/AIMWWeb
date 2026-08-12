using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningBatch6ContractTests
{
    [Fact]
    public void Batch_6_audit_guards_interactive_role_names_states_and_keyboard_entry()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardeningBatch6.cs");

        foreach (var token in new[]
        {
            "visible role=link missing accessible name",
            "enabled role=link is not keyboard focusable",
            "visible menu item missing accessible name",
            "visible menu has no keyboard entry item",
            "visible role=tab missing accessible name",
            "role=tab requires boolean aria-selected",
            "selected visible tab is not keyboard focusable",
            "visible role=option missing accessible name",
            "role=switch requires boolean aria-checked",
            "visible role=combobox missing accessible name",
            "role=combobox requires boolean aria-expanded"
        }) audit.Should().Contain(token);
    }

    [Fact]
    public void Batch_6_browser_tests_cover_public_and_authenticated_route_catalogs()
    {
        var tests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningBatch6RegressionTests.cs");

        tests.Should().Contain("UxRouteCatalog.PublicRoutes");
        tests.Should().Contain("UxRouteCatalog.AuthenticatedRoutes");
        tests.Should().Contain("Public_routes_pass_accessibility_hardening_batch_6");
        tests.Should().Contain("Authenticated_routes_pass_accessibility_hardening_batch_6");
        tests.Should().Contain("UxAccessibilityHardeningBatch6.IssuesAsync");
        tests.Should().Contain("#main-content");
        tests.Should().Contain("/login");
        tests.Should().Contain("/setup");
    }

    [Fact]
    public void Batch_6_manifest_contains_exactly_ten_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_60_TASKS.md");
        var completed = manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal));

        completed.Should().Be(10);
        manifest.Should().Contain("UX010-HARD-051");
        manifest.Should().Contain("UX010-HARD-060");
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
