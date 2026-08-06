namespace AIWordPressManager.Infrastructure.Jobs;

public sealed record SystemDelayJobPayload(int DurationSeconds = 5, string? Message = null);

public sealed class SystemDelayJobHandler : IBackgroundJobHandler
{
    public const string TypeName = "system.delay";

    public string JobType => TypeName;

    public async Task ExecuteAsync(BackgroundJobContext context, CancellationToken cancellationToken)
    {
        var payload = context.GetPayload<SystemDelayJobPayload>() ?? new SystemDelayJobPayload();
        var durationSeconds = Math.Clamp(payload.DurationSeconds, 1, 120);
        var message = string.IsNullOrWhiteSpace(payload.Message)
            ? "Infrastructure job engine test"
            : payload.Message.Trim();

        context.ReportProgress(0, message);

        for (var second = 1; second <= durationSeconds; second++)
        {
            cancellationToken.ThrowIfCancellationRequested();
            await Task.Delay(TimeSpan.FromSeconds(1), cancellationToken);

            var progress = (int)Math.Round(second * 100d / durationSeconds);
            context.ReportProgress(progress, $"{message} ({second}/{durationSeconds})");
        }
    }
}
