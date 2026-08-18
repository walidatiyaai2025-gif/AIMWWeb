using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Persistence.Email;

public sealed class ExecutionJobFailureAlertWorker(
    IServiceScopeFactory scopeFactory,
    ILogger<ExecutionJobFailureAlertWorker> logger) : BackgroundService
{
    private static readonly TimeSpan PollInterval = TimeSpan.FromSeconds(15);
    private static readonly TimeSpan RecoveryWindow = TimeSpan.FromHours(24);
    private const int MaxHandledJobIds = 5_000;

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        var handledJobIds = new HashSet<Guid>();

        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                using var scope = scopeFactory.CreateScope();
                var relay = scope.ServiceProvider.GetRequiredService<ExecutionJobFailureAlertRelay>();
                var result = await relay.RelayPendingAsync(
                    DateTime.UtcNow.Subtract(RecoveryWindow),
                    handledJobIds,
                    stoppingToken);

                foreach (var id in result.HandledJobIds)
                    handledJobIds.Add(id);

                if (handledJobIds.Count > MaxHandledJobIds)
                {
                    handledJobIds.Clear();
                    logger.LogInformation("Cleared the in-memory execution job failure alert relay cache; durable outbox idempotency remains authoritative.");
                }

                if (result.Enqueued > 0 || result.Skipped > 0 || result.Failed > 0)
                {
                    logger.LogInformation(
                        "Execution job failure alert relay pass: scanned {Scanned}, queued/existing {Enqueued}, skipped {Skipped}, retryable failures {Failed}.",
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
                logger.LogError(ex, "The execution job failure email relay pass failed. It will retry without affecting job execution.");
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
