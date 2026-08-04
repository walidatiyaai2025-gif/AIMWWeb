namespace AIWordPressManager.Application.Abstractions.Persistence;

public interface IExecutionJobStore
{
    Task<Guid> StartAsync(Guid siteId, string jobType, CancellationToken cancellationToken = default);
    Task ReportAsync(Guid jobId, int progressPercent, string currentStep, CancellationToken cancellationToken = default);
    Task CompleteAsync(Guid jobId, CancellationToken cancellationToken = default);
    Task FailAsync(Guid jobId, string error, CancellationToken cancellationToken = default);
    Task CancelAsync(Guid jobId, CancellationToken cancellationToken = default);
    Task<IReadOnlyList<ExecutionJobListItem>> GetRecentAsync(Guid? siteId = null, int take = 200, CancellationToken cancellationToken = default);
    Task<ExecutionJobListItem?> GetAsync(Guid jobId, CancellationToken cancellationToken = default);
}
