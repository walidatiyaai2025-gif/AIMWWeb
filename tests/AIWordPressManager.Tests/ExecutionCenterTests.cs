using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

[Collection(WorkflowTestCollection.Name)]
public sealed class ExecutionCenterTests : IDisposable
{
    private readonly ExecutionCenterService _service;

    public ExecutionCenterTests()
    {
        DeleteDatabase();
        _service = new ExecutionCenterService();
    }

    [Fact]
    public void Enqueue_PersistsJobAndActivity()
    {
        var job = _service.Enqueue("Synchronize content", "Synchronization", "Test Site", 25);

        _service.GetJobs().Should().Contain(x => x.Id == job.Id && x.Status == "Waiting");
        _service.GetActivities(100).Should().Contain(x => x.JobId == job.Id && x.Message.Contains("Queued"));
    }

    [Fact]
    public void Cancel_ThenRetry_ReturnsJobToWaitingState()
    {
        var job = _service.Enqueue("Bulk update", "Bulk Update", "Test Site", 10);

        _service.Cancel(job.Id);
        _service.GetJobs().Single(x => x.Id == job.Id).Status.Should().Be("Cancelled");

        _service.Retry(job.Id);
        var retried = _service.GetJobs().Single(x => x.Id == job.Id);
        retried.Status.Should().Be("Waiting");
        retried.Progress.Should().Be(0);
        retried.ProcessedItems.Should().Be(0);
    }

    [Fact]
    public void Restart_PreservesQueuedJobs()
    {
        var job = _service.Enqueue("Persistent job", "Test", "Test Site", 5);
        _service.Dispose();

        using var restarted = new ExecutionCenterService();

        restarted.GetJobs().Should().Contain(x => x.Id == job.Id);
    }

    private static void DeleteDatabase()
    {
        var directory = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "AIWordPressManager",
            "Data");

        foreach (var suffix in new[] { string.Empty, "-wal", "-shm" })
        {
            var path = Path.Combine(directory, "execution-center.db" + suffix);
            if (File.Exists(path)) File.Delete(path);
        }
    }

    public void Dispose()
    {
        _service.Dispose();
    }
}
