using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Persistence.Email;

public sealed class SubscriptionBillingEmailAlertWorker(
    IServiceScopeFactory scopeFactory,
    ILogger<SubscriptionBillingEmailAlertWorker> logger) : BackgroundService
{
    private static readonly TimeSpan PollInterval = TimeSpan.FromSeconds(15);
    private static readonly TimeSpan RecoveryWindow = TimeSpan.FromDays(30);
    private const int MaxHandledEventKeys = 10_000;

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        var handledEventKeys = new HashSet<string>(StringComparer.Ordinal);

        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                using var scope = scopeFactory.CreateScope();
                var relay = scope.ServiceProvider.GetRequiredService<SubscriptionBillingEmailAlertRelay>();
                var result = await relay.RelayPendingAsync(
                    DateTime.UtcNow.Subtract(RecoveryWindow),
                    handledEventKeys,
                    stoppingToken);

                foreach (var eventKey in result.HandledEventKeys)
                    handledEventKeys.Add(eventKey);

                if (handledEventKeys.Count > MaxHandledEventKeys)
                {
                    handledEventKeys.Clear();
                    logger.LogInformation(
                        "Cleared the in-memory subscription billing email relay cache; durable outbox idempotency remains authoritative.");
                }

                if (result.Enqueued > 0 || result.Skipped > 0 || result.Failed > 0)
                {
                    logger.LogInformation(
                        "Subscription billing email relay pass: scanned {Scanned}, queued/existing {Enqueued}, skipped {Skipped}, retryable failures {Failed}.",
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
                    "The subscription billing email relay pass failed. It will retry without affecting billing reconciliation.");
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
