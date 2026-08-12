using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010AccessibilityHardeningContractTests
{
    [Fact]
    public void Extended_accessibility_gate_guards_all_ten_hardening_rules()
    {
        var audit = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxAccessibilityHardening.cs");
        var browserTests = ReadRepositoryFile("tests/AIWordPressManager.UxTests/AccessibilityHardeningRegressionTests.cs");

        foreach (var marker in new[]
        {
            "UX010-HARD-001",
            "UX010-HARD-002",
            "UX010-HARD-003",
            "UX010-HARD-004",
            "UX010-HARD-005",
            "UX010-HARD-006",
            "UX010-HARD-007",
            "UX010-HARD-008",
            "UX010-HARD-009",
            "UX010-HARD-010"
        }) audit.Should().Contain(marker);

        audit.Should().Contain("broken aria-labelledby reference");
        audit.Should().Contain("broken aria-describedby reference");
        audit.Should().Contain("broken aria-controls reference");
        audit.Should().Contain("broken aria-owns reference");
        audit.Should().Contain("role=button is not keyboard focusable");
        audit.Should().Contain("visible dialog missing accessible name");
        audit.Should().Contain("visible iframe missing title");
        audit.Should().Contain("aria-hidden subtree contains focusable content");
        audit.Should().Contain("role=img missing accessible name");
        audit.Should().Contain("invalid ${attribute} value");

        browserTests.Should().Contain("Public_routes_pass_extended_aria_semantics");
        browserTests.Should().Contain("Authenticated_routes_pass_extended_aria_semantics");
        browserTests.Should().Contain("UxAccessibilityHardening.IssuesAsync");
    }

    [Fact]
    public void Hardening_manifest_tracks_exactly_ten_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_010_HARDENING_10_TASKS.md");
        var completed = manifest.Split('\n').Count(line => line.StartsWith("- [x] UX010-HARD-", StringComparison.Ordinal));

        completed.Should().Be(10);
        manifest.Should().Contain("UX010-HARD-001");
        manifest.Should().Contain("UX010-HARD-010");
        manifest.Should().Contain("stacked on UX-010");
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
