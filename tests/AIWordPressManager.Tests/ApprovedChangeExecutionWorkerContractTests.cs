namespace AIWordPressManager.Tests;

public sealed class ApprovedChangeExecutionWorkerContractTests
{
    [Fact]
    public void Production_host_registers_a_real_approved_change_worker()
    {
        var root = FindRepositoryRoot();
        var program = File.ReadAllText(Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Program.cs"));
        var worker = File.ReadAllText(Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Services", "ApprovedChangeExecutionWorker.cs"));

        Assert.Contains("AddHostedService<ApprovedChangeExecutionWorker>()", program, StringComparison.Ordinal);
        Assert.Contains("GetPendingExternalJobs", worker, StringComparison.Ordinal);
        Assert.Contains("TryStartExternal", worker, StringComparison.Ordinal);
        Assert.Contains("ApprovedChangePolicy.TryGetRequest", worker, StringComparison.Ordinal);
        Assert.Contains("BackgroundExecutionIdentity.Push", worker, StringComparison.Ordinal);
        Assert.Contains("BackgroundContentMutationAuthorization.Push", worker, StringComparison.Ordinal);
        Assert.Contains("IWordPressPostEditorService", worker, StringComparison.Ordinal);
        Assert.Contains("ApprovedChangePolicy.RemoteMatches", worker, StringComparison.Ordinal);
        Assert.Contains("UpdateAsync", worker, StringComparison.Ordinal);
        Assert.Contains("SynchronizeAsync(siteId, cancellationToken, forceFullRefresh: true)", worker, StringComparison.Ordinal);
        Assert.Contains("CompleteExternal", worker, StringComparison.Ordinal);
        Assert.Contains("MarkExecutionSucceeded", worker, StringComparison.Ordinal);
        Assert.Contains("FailExternal", worker, StringComparison.Ordinal);
        Assert.Contains("RecordExecutionFailed", worker, StringComparison.Ordinal);

        Assert.DoesNotContain("Task.Delay(TimeSpan.FromSeconds(1))", worker, StringComparison.Ordinal);
        Assert.DoesNotContain("Progress = 100", worker, StringComparison.Ordinal);
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
