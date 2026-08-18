using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Persistence.Email;

public sealed class SiteSyncFailureAlertWorker(
    IServiceScopeFactory scopeFactory,
    ILogger<SiteSyncFailureAlertWorker> logger) : BackgroundService
{
    private static readonly TimeSpan PollInterval = TimeSpan.FromSeconds(15);
    private static readonly TimeSpan RecoveryWindow = TimeSpan.FromHours(24);
    private const int MaxHandledRunIds = 5_000;

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        var handledRunIds = new HashSet<Guid>();

        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                using var scope = scopeFactory.CreateScope();
                var relay = scope.ServiceProvider.GetRequiredService<SiteSyncFailureAlertRelay>();
                var result = await relay.RelayPendingAsync(
                    DateTime.UtcNow.Subtract(RecoveryWindow),
                    handledRunIds,
                    stoppingToken);

                foreach (var id in result.HandledRunIds)
                    handledRunIds.Add(id);

                if (handledRunIds.Count > MaxHandledRunIds)
                {
                    handledRunIds.Clear();
                    logger.LogInformation("Cleared the in-memory sync failure alert relay cache; durable outbox idempotency remains authoritative.");
                }

                if (result.Enqueued > 0 || result.Skipped > 0 || result.Failed > 0)
                {
                    logger.LogInformation(
                        "Sync failure alert relay pass: scanned {Scanned}, queued/existing {Enqueued}, skipped {Skipped}, retryable failures {Failed}.",
                        result.Scanned,
                        result.Enqueued,
                        result.Skipped,
                        result.Failed);
                }
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                logger.LogError(ex, "The sync failure email alert relay pass failed. It will retry without affecting WordPress synchronization.");
            }

            try
            {
                await Task.Delay(PollInterval, stoppingToken);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
        }
    }
}
