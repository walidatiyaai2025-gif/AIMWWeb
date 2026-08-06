using System.Text.Json;
using Microsoft.AspNetCore.Components;
using Microsoft.AspNetCore.Components.Web;

namespace AIWordPressManager.Web.Components;

public sealed class TrackedErrorBoundary : ErrorBoundary
{
    [Inject] private ILogger<TrackedErrorBoundary> Logger { get; set; } = default!;

    public string? ErrorId { get; private set; }
    public string? CorrelationId { get; private set; }

    protected override Task OnErrorAsync(Exception exception)
    {
        ErrorId = $"ERR-{DateTime.UtcNow:yyyyMMdd-HHmmss}-{Guid.NewGuid():N}"[..32].ToUpperInvariant();
        CorrelationId = Guid.NewGuid().ToString("N");

        Logger.LogError(exception,
            "Unhandled Blazor component exception {ErrorId}. CorrelationId: {CorrelationId}",
            ErrorId,
            CorrelationId);

        try
        {
            var directory = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AIWordPressManager", "Logs");
            Directory.CreateDirectory(directory);
            var entry = new
            {
                TimestampUtc = DateTime.UtcNow,
                Level = "Error",
                Event = "BlazorComponentException",
                ErrorId,
                CorrelationId,
                ExceptionType = exception.GetType().FullName,
                exception.Message,
                exception.StackTrace
            };
            File.AppendAllText(Path.Combine(directory, $"aiwm-{DateTime.UtcNow:yyyyMMdd}.log"), JsonSerializer.Serialize(entry) + Environment.NewLine);
        }
        catch (Exception loggingException)
        {
            Logger.LogWarning(loggingException, "Failed to persist the component exception log.");
        }

        return Task.CompletedTask;
    }
}
