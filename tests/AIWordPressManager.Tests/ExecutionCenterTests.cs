using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

[Collection(WorkflowTestCollection.Name)]
public sealed class ExecutionCenterTests : IDisposable
{
    private readonly string _testDirectory;
    private readonly string _databasePath;
    private readonly ExecutionCenterService _service;

    public ExecutionCenterTests()
    {
        _testDirectory = Path.Combine(Path.GetTempPath(), "AIWordPressManager.Tests", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_testDirectory);
        _databasePath = Path.Combine(_testDirectory, "execution-center.db");
        _service = new ExecutionCenterService(
            _databasePath,
            enableBackgroundWorker: false,
            enableSeedData: false);
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

        using var restarted = new ExecutionCenterService(
            _databasePath,
            enableBackgroundWorker: false,
            enableSeedData: false);

        restarted.GetJobs().Should().Contain(x => x.Id == job.Id);
    }

    public void Dispose()
    {
        _service.Dispose();
        TryDeleteDirectory(_testDirectory);
    }

    private static void TryDeleteDirectory(string directory)
    {
        if (!Directory.Exists(directory)) return;

        for (var attempt = 1; attempt <= 5; attempt++)
        {
            try
            {
                Directory.Delete(directory, recursive: true);
                return;
            }
            catch (IOException) when (attempt < 5)
            {
                Thread.Sleep(50 * attempt);
            }
            catch (UnauthorizedAccessException) when (attempt < 5)
            {
                Thread.Sleep(50 * attempt);
            }
        }
    }
}
