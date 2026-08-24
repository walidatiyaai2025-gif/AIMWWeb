using Xunit;

namespace AIWordPressManager.Tests;

public sealed class SiteOperationsUiTruthfulnessContractTests
{
    [Fact]
    public void Reliability_metrics_are_limited_to_retained_connection_and_sync_history()
    {
        var root = FindRepositoryRoot();
        var pagePath = Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Components", "Pages", "SiteReliability.razor");
        var servicePath = Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Services", "SiteOperationHistoryService.cs");
        var page = File.ReadAllText(pagePath);
        var service = File.ReadAllText(servicePath);

        Assert.Contains("private const int RetainedHistoryLimit = 2000;", page, StringComparison.Ordinal);
        Assert.Contains("History.GetAll(ownerUserId, _sites.Select(x => x.Id).ToArray(), RetainedHistoryLimit)", page, StringComparison.Ordinal);
        Assert.Contains("ReliabilityOperations.Contains(x.Operation)", page, StringComparison.Ordinal);
        Assert.Contains("\"connection-test\"", page, StringComparison.Ordinal);
        Assert.Contains("\"synchronization\"", page, StringComparison.Ordinal);
        Assert.Contains("not a live health check or a production-readiness claim", page, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("_historyMayBeTruncated", page, StringComparison.Ordinal);
        Assert.DoesNotContain("History.GetAll(ownerUserId, _sites.Select(x => x.Id).ToArray(), 5000)", page, StringComparison.Ordinal);
        Assert.DoesNotContain("Excellent", page, StringComparison.Ordinal);
        Assert.DoesNotContain("Critical", page, StringComparison.Ordinal);
        Assert.DoesNotContain("href=\"#\"", page, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("javascript:", page, StringComparison.OrdinalIgnoreCase);

        Assert.Contains(".Take(Math.Clamp(take, 1, 2000))", service, StringComparison.Ordinal);
        Assert.Contains("if (items.Count > 2000)", service, StringComparison.Ordinal);
    }

    [Fact]
    public void Operations_hub_and_history_disclose_retained_scope_and_controls_have_real_destinations()
    {
        var root = FindRepositoryRoot();
        var pages = Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Components", "Pages");
        var hub = File.ReadAllText(Path.Combine(pages, "SiteOperationsHub.razor"));
        var overview = File.ReadAllText(Path.Combine(pages, "SiteOperationsOverview.razor"));
        var details = File.ReadAllText(Path.Combine(pages, "SiteOperationDetails.razor"));

        Assert.Contains("private const int RetainedHistoryLimit = 2000;", hub, StringComparison.Ordinal);
        Assert.Contains("History.GetAll(ownerUserId, ownedSiteIds, RetainedHistoryLimit)", hub, StringComparison.Ordinal);
        Assert.Contains("_historyMayBeTruncated", hub, StringComparison.Ordinal);
        Assert.Contains("\"/site-operations\"", hub, StringComparison.Ordinal);
        Assert.Contains("\"/site-reliability\"", hub, StringComparison.Ordinal);
        Assert.Contains("\"/operations/maintenance\"", hub, StringComparison.Ordinal);
        Assert.Contains("\"/module/sync\"", hub, StringComparison.Ordinal);
        Assert.Contains("\"/sites\"", hub, StringComparison.Ordinal);

        Assert.Contains("private const int RetainedHistoryLimit = 2000;", overview, StringComparison.Ordinal);
        Assert.Contains("History.GetAll(ownerUserId, _sites.Select(x => x.Id).ToArray(), RetainedHistoryLimit)", overview, StringComparison.Ordinal);
        Assert.Contains("cover retained records only", overview, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("CsvDownloadHref", overview, StringComparison.Ordinal);
        Assert.Contains("BuildCsv()", overview, StringComparison.Ordinal);
        Assert.Contains("/operations/sites/{item.Id}", overview, StringComparison.Ordinal);

        Assert.Contains("History.GetById(ownerUserId, ownedSiteIds, OperationId)", details, StringComparison.Ordinal);
        Assert.Contains("navigator.clipboard.writeText", details, StringComparison.Ordinal);
        Assert.Contains("/module/execution?siteId={_item.SiteId}", details, StringComparison.Ordinal);
        Assert.Contains("/sites/{_item.SiteId}/connection", details, StringComparison.Ordinal);

        foreach (var page in new[] { hub, overview, details })
        {
            Assert.DoesNotContain("href=\"#\"", page, StringComparison.OrdinalIgnoreCase);
            Assert.DoesNotContain("javascript:", page, StringComparison.OrdinalIgnoreCase);
        }
    }

    private static DirectoryInfo FindRepositoryRoot()
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "AIWordPressManager.Web.sln")))
                return current;
            current = current.Parent;
        }

        throw new DirectoryNotFoundException("Could not locate AIMWWeb repository root from the test output directory.");
    }
}
