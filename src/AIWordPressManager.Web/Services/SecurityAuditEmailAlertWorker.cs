using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Web.Services;

public sealed class SecurityAuditEmailAlertWorker(
    IServiceScopeFactory scopeFactory,
    ILogger<SecurityAuditEmailAlertWorker> logger) : BackgroundService
{
    private static readonly TimeSpan PollInterval = TimeSpan.FromSeconds(15);
    private static readonly TimeSpan RecoveryWindow = TimeSpan.FromHours(24);
    private const int MaxHandledEventIds = 5_000;

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        var handledEventIds = new HashSet<Guid>();

        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                using var scope = scopeFactory.CreateScope();
                var relay = scope.ServiceProvider.GetRequiredService<SecurityAuditEmailAlertRelay>();
                var result = await relay.RelayPendingAsync(
                    DateTime.UtcNow.Subtract(RecoveryWindow),
                    handledEventIds,
                    stoppingToken);

                foreach (var id in result.HandledEventIds)
                    handledEventIds.Add(id);

                if (handledEventIds.Count > MaxHandledEventIds)
                {
                    handledEventIds.Clear();
                    logger.LogInformation(
                        "Cleared the in-memory security audit email relay cache; durable outbox idempotency remains authoritative.");
                }

                if (result.Enqueued > 0 || result.Skipped > 0 || result.Failed > 0)
                {
                    logger.LogInformation(
                        "Security audit email relay pass: scanned {Scanned}, queued/existing {Enqueued}, skipped {Skipped}, retryable failures {Failed}.",
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
                logger.LogError(
                    ex,
                    "The security audit email relay pass failed. It will retry without affecting authentication or security operations.");
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
