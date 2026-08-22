using Xunit;

namespace AIWordPressManager.Tests;

public sealed class WorkspaceHubNoMockContractTests
{
    [Fact]
    public void Workspace_hub_exposes_real_routes_without_static_readiness_claims()
    {
        var root = FindRepositoryRoot();
        var path = Path.Combine(
            root.FullName,
            "src",
            "AIWordPressManager.Web",
            "Components",
            "Pages",
            "WorkspaceHub.razor");

        var source = File.ReadAllText(path);

        Assert.DoesNotContain("bool Ready", source, StringComparison.Ordinal);
        Assert.DoesNotContain("item.Ready", source, StringComparison.Ordinal);
        Assert.DoesNotContain("In Progress", source, StringComparison.Ordinal);
        Assert.DoesNotContain("قيد التطوير", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Bulk Operations", source, StringComparison.Ordinal);
        Assert.DoesNotContain("History & Rollback", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Execution Modes", source, StringComparison.Ordinal);

        var expectedRoutes = new[]
        {
            "/module/posts",
            "/module/pages",
            "/module/media",
            "/module/taxonomy",
            "/module/comments",
            "/module/users",
            "/module/seo-audit",
            "/module/seo-suggestions",
            "/approvals",
            "/ai-center",
            "/settings/ai-providers",
            "/settings/ai-prompts",
            "/module/execution",
            "/module/sync",
            "/module/schedules",
            "/module/logs",
            "/module/backups",
            "/module/reports"
        };

        foreach (var route in expectedRoutes)
        {
            Assert.Contains(route, source, StringComparison.Ordinal);
        }

        Assert.Contains("href=\"@item.Href\"", source, StringComparison.Ordinal);
        Assert.Contains("data-testid=\"workspace-link\"", source, StringComparison.Ordinal);
        Assert.Contains("Real workspaces only", source, StringComparison.Ordinal);
    }

    private static DirectoryInfo FindRepositoryRoot()
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "AIWordPressManager.Web.sln")))
            {
                return current;
            }

            current = current.Parent;
        }

        throw new DirectoryNotFoundException("Could not locate AIMWWeb repository root from the test output directory.");
    }
}
