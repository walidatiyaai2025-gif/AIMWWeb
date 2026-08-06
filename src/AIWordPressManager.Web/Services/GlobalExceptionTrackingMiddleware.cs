using System.Diagnostics;
using System.Text.Json;

namespace AIWordPressManager.Web.Services;

public sealed class GlobalExceptionTrackingMiddleware
{
    private readonly RequestDelegate _next;
    private readonly ILogger<GlobalExceptionTrackingMiddleware> _logger;
    private readonly string _logDirectory;

    public GlobalExceptionTrackingMiddleware(RequestDelegate next, ILogger<GlobalExceptionTrackingMiddleware> logger)
    {
        _next = next;
        _logger = logger;
        _logDirectory = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AIWordPressManager", "Logs");
        Directory.CreateDirectory(_logDirectory);
    }

    public async Task InvokeAsync(HttpContext context)
    {
        var started = Stopwatch.GetTimestamp();
        var correlationId = GetOrCreateCorrelationId(context);
        context.Response.Headers["X-Correlation-ID"] = correlationId;

        try
        {
            await _next(context);
            WriteRequestEvent(context, correlationId, started, null);
        }
        catch (Exception ex)
        {
            var errorId = $"ERR-{DateTime.UtcNow:yyyyMMdd-HHmmss}-{Guid.NewGuid():N}"[..32].ToUpperInvariant();
            context.Items["ErrorId"] = errorId;
            context.Items["CorrelationId"] = correlationId;

            _logger.LogError(ex,
                "Unhandled exception {ErrorId} for {Method} {Path}. CorrelationId: {CorrelationId}",
                errorId,
                context.Request.Method,
                context.Request.Path,
                correlationId);

            WriteRequestEvent(context, correlationId, started, new ExceptionEvent(errorId, ex.GetType().FullName ?? ex.GetType().Name, ex.Message, ex.StackTrace));
            throw;
        }
    }

    private static string GetOrCreateCorrelationId(HttpContext context)
    {
        var supplied = context.Request.Headers["X-Correlation-ID"].FirstOrDefault();
        var value = string.IsNullOrWhiteSpace(supplied) ? Guid.NewGuid().ToString("N") : supplied.Trim();
        context.TraceIdentifier = value;
        return value;
    }

    private void WriteRequestEvent(HttpContext context, string correlationId, long started, ExceptionEvent? exception)
    {
        try
        {
            var elapsed = Stopwatch.GetElapsedTime(started).TotalMilliseconds;
            var item = new
            {
                TimestampUtc = DateTime.UtcNow,
                Level = exception is null ? "Information" : "Error",
                Event = exception is null ? "HttpRequestCompleted" : "UnhandledException",
                CorrelationId = correlationId,
                TraceIdentifier = context.TraceIdentifier,
                Method = context.Request.Method,
                Path = context.Request.Path.Value,
                QueryString = context.Request.QueryString.Value,
                StatusCode = exception is null ? context.Response.StatusCode : 500,
                ElapsedMilliseconds = Math.Round(elapsed, 2),
                User = context.User.Identity?.Name,
                RemoteIp = context.Connection.RemoteIpAddress?.ToString(),
                Exception = exception
            };

            var path = Path.Combine(_logDirectory, $"aiwm-{DateTime.UtcNow:yyyyMMdd}.log");
            File.AppendAllText(path, JsonSerializer.Serialize(item) + Environment.NewLine);
        }
        catch (Exception loggingException)
        {
            _logger.LogWarning(loggingException, "Failed to write structured application log.");
        }
    }

    private sealed record ExceptionEvent(string ErrorId, string Type, string Message, string? StackTrace);
}

public static class GlobalExceptionTrackingExtensions
{
    public static IApplicationBuilder UseGlobalExceptionTracking(this IApplicationBuilder app)
        => app.UseMiddleware<GlobalExceptionTrackingMiddleware>();
}
