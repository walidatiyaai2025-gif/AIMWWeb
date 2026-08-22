using Xunit;

namespace AIWordPressManager.Tests;

public sealed class ModuleWorkspaceNoMockContractTests
{
    [Fact]
    public void Generic_module_workspace_contains_no_runtime_mock_dataset_or_inert_demo_controls()
    {
        var root = FindRepositoryRoot();
        var path = Path.Combine(
            root.FullName,
            "src",
            "AIWordPressManager.Web",
            "Components",
            "Pages",
            "ModuleWorkspace.razor");

        var source = File.ReadAllText(path);
        var forbidden = new[]
        {
            "SampleRows",
            "SeoIssues",
            "QueueColumns",
            "private string[] Providers",
            "private string[] Logs",
            "seo-ring\">78",
            "Missing meta description on 12 pages",
            "7 images without alt text",
            "Weak internal links in 9 posts",
            "2 titles exceed recommended length",
            "backup-2026-08-0",
            "image-{i}.jpg",
            "New action</button>",
            "Create now</button>",
            "Restore</button>"
        };

        foreach (var value in forbidden)
        {
            Assert.DoesNotContain(value, source, StringComparison.Ordinal);
        }

        Assert.Contains("SiteService.GetSitesAsync()", source, StringComparison.Ordinal);
        Assert.Contains("/sites/{site.Id}/seo", source, StringComparison.Ordinal);
        Assert.Contains("data-testid=\"module-unavailable\"", source, StringComparison.Ordinal);
        Assert.Contains("data-testid=\"seo-audit-site-picker\"", source, StringComparison.Ordinal);
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
