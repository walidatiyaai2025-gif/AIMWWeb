using Microsoft.Extensions.Hosting;

namespace AIWordPressManager.Infrastructure.Security;

internal sealed class SecretProtectionRuntimeLockHostedService : IHostedService, IDisposable
{
    private RuntimeLockLease? _lease;

    public Task StartAsync(CancellationToken cancellationToken)
    {
        cancellationToken.ThrowIfCancellationRequested();
        _lease = SecretProtectionStorage.AcquireWebRuntimeLease();
        return Task.CompletedTask;
    }

    public Task StopAsync(CancellationToken cancellationToken)
    {
        Dispose();
        return Task.CompletedTask;
    }

    public void Dispose()
    {
        Interlocked.Exchange(ref _lease, null)?.Dispose();
    }
}
