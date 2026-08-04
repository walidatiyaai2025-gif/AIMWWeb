namespace AIWordPressManager.Application.Abstractions;

public interface IJobCancellationRegistry
{
    IDisposable Register(Guid jobId, CancellationTokenSource cancellationTokenSource);
    bool TryCancel(Guid jobId);
    bool IsRegistered(Guid jobId);
}
