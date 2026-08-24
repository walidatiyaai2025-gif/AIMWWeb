using Xunit;

namespace AIWordPressManager.Tests;

public sealed class SiteOperationsUiTruthfulnessContractTests
{
    [Fact]
    public void Operations_hub_cards_metrics_and_failure_state_are_honest_read_surfaces()
    {
        var root = FindRepositoryRoot();
        var page = ReadPage(root, "SiteOperationsHub.razor");

        Assert.Contains("retained operation history", page, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("Recorded 30-day operations", page, StringComparison.Ordinal);
        Assert.Contains("Recorded success rate", page, StringComparison.Ordinal);
        Assert.Contains("private const int RetainedHistoryLimit = 2000;", page, StringComparison.Ordinal);
        Assert.Contains("_historyMayBeTruncated", page, StringComparison.Ordinal);

        Assert.Contains("\"/site-operations\"", page, StringComparison.Ordinal);
        Assert.Contains("\"/site-reliability\"", page, StringComparison.Ordinal);
        Assert.Contains("\"/operations/maintenance\"", page, StringComparison.Ordinal);
        Assert.Contains("\"/module/sync\"", page, StringComparison.Ordinal);
        Assert.Contains("\"/sites\"", page, StringComparison.Ordinal);

        Assert.Contains("Operation history could not be loaded", page, StringComparison.Ordinal);
        Assert.Contains("@onclick=\"ReloadAsync\"", page, StringComparison.Ordinal);
        Assert.Contains("No operations have been recorded yet.", page, StringComparison.Ordinal);
        Assert.Contains("No metrics or stale activity are being shown as current data", page, StringComparison.Ordinal);

        AssertNoDeadLinkMarkers(page);
    }

    [Fact]
    public void Operations_overview_filters_csv_details_and_error_states_have_real_destinations()
    {
        var root = FindRepositoryRoot();
        var page = ReadPage(root, "SiteOperationsOverview.razor");
        var app = File.ReadAllText(Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Components", "App.razor"));
        var csvHelper = File.ReadAllText(Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "wwwroot", "js", "app-csv.js"));

        Assert.Contains("private const int RetainedHistoryLimit = 2000;", page, StringComparison.Ordinal);
        Assert.Contains("History.GetAll(ownerUserId, _sites.Select(x => x.Id).ToArray(), RetainedHistoryLimit)", page, StringComparison.Ordinal);
        Assert.Contains("cover retained records only", page, StringComparison.OrdinalIgnoreCase);

        Assert.Contains("@bind=\"_query\"", page, StringComparison.Ordinal);
        Assert.Contains("@bind=\"_siteFilter\"", page, StringComparison.Ordinal);
        Assert.Contains("@bind=\"_statusFilter\"", page, StringComparison.Ordinal);
        Assert.Contains("@bind=\"_fromDate\"", page, StringComparison.Ordinal);
        Assert.Contains("@bind=\"_toDate\"", page, StringComparison.Ordinal);
        Assert.Contains("private bool HasValidDateRange", page, StringComparison.Ordinal);
        Assert.Contains("The start date cannot be after the end date", page, StringComparison.Ordinal);
        Assert.Contains("ResetFilters", page, StringComparison.Ordinal);

        Assert.Contains("disabled=\"@(!CanExportCsv)\"", page, StringComparison.Ordinal);
        Assert.Contains("There are no matching operations to export", page, StringComparison.Ordinal);
        Assert.Contains("await JS.InvokeVoidAsync(\"appCsv.download\"", page, StringComparison.Ordinal);
        Assert.DoesNotContain("data:text/csv", page, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("<script src=\"js/app-csv.js\"></script>", app, StringComparison.Ordinal);
        Assert.Contains("new Blob", csvHelper, StringComparison.Ordinal);
        Assert.Contains("URL.createObjectURL", csvHelper, StringComparison.Ordinal);
        Assert.Contains("link.click()", csvHelper, StringComparison.Ordinal);

        Assert.Contains("/operations/sites/{item.Id}", page, StringComparison.Ordinal);
        Assert.Contains("No operations match the current filters.", page, StringComparison.Ordinal);
        Assert.Contains("Operation history could not be loaded", page, StringComparison.Ordinal);
        Assert.Contains("The storage failure has not been presented as an empty history", page, StringComparison.Ordinal);
        Assert.Contains("@onclick=\"ReloadAsync\"", page, StringComparison.Ordinal);

        AssertNoDeadLinkMarkers(page);
    }

    [Fact]
    public void Operation_details_distinguish_not_found_from_load_failure_and_clipboard_success_is_not_fake()
    {
        var root = FindRepositoryRoot();
        var page = ReadPage(root, "SiteOperationDetails.razor");

        Assert.Contains("History.GetById(ownerUserId, ownedSiteIds, OperationId)", page, StringComparison.Ordinal);
        Assert.Contains("Operation details could not be loaded", page, StringComparison.Ordinal);
        Assert.Contains("Operation not found", page, StringComparison.Ordinal);
        Assert.Contains("The storage failure has not been presented as an operation-not-found result", page, StringComparison.Ordinal);
        Assert.Contains("@onclick=\"LoadAsync\"", page, StringComparison.Ordinal);

        Assert.Contains("disabled=\"@_copying\"", page, StringComparison.Ordinal);
        var clipboardCall = page.IndexOf("await JS.InvokeVoidAsync(\"navigator.clipboard.writeText\"", StringComparison.Ordinal);
        var successFlag = page.IndexOf("_copySucceeded = true;", StringComparison.Ordinal);
        var successMessage = page.IndexOf("Report copied to the clipboard.", StringComparison.Ordinal);
        Assert.True(clipboardCall >= 0, "Clipboard write must call the browser clipboard API.");
        Assert.True(successFlag > clipboardCall, "Copy success must only be recorded after the browser clipboard write returns successfully.");
        Assert.True(successMessage > clipboardCall, "The visible copy-success message must follow the real clipboard write.");
        Assert.Contains("No copy success was reported", page, StringComparison.Ordinal);

        Assert.Contains("/site-operations", page, StringComparison.Ordinal);
        Assert.Contains("ExecutionJobId.HasValue", page, StringComparison.Ordinal);
        Assert.Contains("/module/execution?siteId={_item.SiteId}", page, StringComparison.Ordinal);
        Assert.Contains("/sites/{_item.SiteId}/connection", page, StringComparison.Ordinal);
        Assert.Contains("No technical details were recorded for this operation.", page, StringComparison.Ordinal);

        AssertNoDeadLinkMarkers(page);
    }

    private static string ReadPage(DirectoryInfo root, string fileName) =>
        File.ReadAllText(Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Components", "Pages", fileName));

    private static void AssertNoDeadLinkMarkers(string page)
    {
        Assert.DoesNotContain("href=\"#\"", page, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("javascript:", page, StringComparison.OrdinalIgnoreCase);
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
