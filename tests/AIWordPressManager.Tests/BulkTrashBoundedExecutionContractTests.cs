namespace AIWordPressManager.Tests;

public sealed class BulkTrashBoundedExecutionContractTests
{
    [Fact]
    public void Bulk_trash_is_bounded_and_does_not_blindly_retry_mutations()
    {
        var root = FindRepositoryRoot();
        var servicePath = Path.Combine(
            root.FullName,
            "src",
            "AIWordPressManager.Web",
            "Services",
            "BulkTrashExecutionService.cs");
        var pagePath = Path.Combine(
            root.FullName,
            "src",
            "AIWordPressManager.Web",
            "Components",
            "Pages",
            "GlobalPagesExplorer.razor");

        var service = File.ReadAllText(servicePath);
        var page = File.ReadAllText(pagePath);

        Assert.Contains("OperationTimeout = TimeSpan.FromSeconds(45)", service, StringComparison.Ordinal);
        Assert.Contains("CacheRefreshTimeout = TimeSpan.FromSeconds(15)", service, StringComparison.Ordinal);
        Assert.Contains("CancellationTokenSource.CreateLinkedTokenSource(cancellationToken)", service, StringComparison.Ordinal);
        Assert.Contains("operationCts.CancelAfter(OperationTimeout)", service, StringComparison.Ordinal);
        Assert.Contains("refreshCts.CancelAfter(CacheRefreshTimeout)", service, StringComparison.Ordinal);
        Assert.Contains("انتهت مهلة تنفيذ العملية مع WordPress", service, StringComparison.Ordinal);

        Assert.DoesNotContain("for (var attempt = 1; attempt <= 3; attempt++)", service, StringComparison.Ordinal);
        Assert.DoesNotContain("Task.Delay(TimeSpan.FromSeconds(attempt)", service, StringComparison.Ordinal);

        // The confirmation button must always leave Busy state when the bounded service returns.
        Assert.Contains("finally\n        {\n            _bulkBusy = false;\n        }", page.Replace("\r\n", "\n"), StringComparison.Ordinal);
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
