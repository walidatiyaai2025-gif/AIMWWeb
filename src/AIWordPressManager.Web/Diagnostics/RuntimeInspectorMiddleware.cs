using System.Diagnostics;
using System.Security.Claims;

namespace AIWordPressManager.Web.Diagnostics;

public sealed class RuntimeInspectorMiddleware
{
    private const string CorrelationHeader = "X-Correlation-ID";
    private readonly RequestDelegate _next;
    private readonly ILogger<RuntimeInspectorMiddleware> _logger;
    private readonly RuntimeInspectorOptions _options;

    public RuntimeInspectorMiddleware(
        RequestDelegate next,
        ILogger<RuntimeInspectorMiddleware> logger,
        RuntimeInspectorOptions options)
    {
        _next = next;
        _logger = logger;
        _options = options;
    }

    public async Task InvokeAsync(HttpContext context)
    {
        if (!_options.Enabled)
        {
            await _next(context);
            return;
        }

        var correlationId = ResolveCorrelationId(context);
        context.Response.Headers[CorrelationHeader] = correlationId;

        var requestPath = context.Request.Path.Value ?? "/";
        if (_options.IncludeRequestQueryString && context.Request.QueryString.HasValue)
            requestPath += RuntimeLogRedactor.Redact(context.Request.QueryString.Value);

        var userId = context.User.FindFirstValue(ClaimTypes.NameIdentifier)
                     ?? context.User.Identity?.Name
                     ?? "anonymous";
        var stopwatch = Stopwatch.StartNew();

        using var scope = _logger.BeginScope(new Dictionary<string, object?>
        {
            ["correlationId"] = correlationId,
            ["traceId"] = Activity.Current?.TraceId.ToString(),
            ["requestId"] = context.TraceIdentifier,
            ["httpMethod"] = context.Request.Method,
            ["requestPath"] = requestPath,
            ["host"] = context.Request.Host.Value,
            ["userId"] = userId
        });

        try
        {
            await _next(context);

            if (context.Response.StatusCode >= StatusCodes.Status500InternalServerError)
            {
                _logger.LogError(
                    "HTTP request completed with server error status {StatusCode} in {ElapsedMs} ms.",
                    context.Response.StatusCode,
                    stopwatch.ElapsedMilliseconds);
            }
            else if (context.Response.StatusCode >= StatusCodes.Status400BadRequest)
            {
                _logger.LogWarning(
                    "HTTP request completed with client error status {StatusCode} in {ElapsedMs} ms.",
                    context.Response.StatusCode,
                    stopwatch.ElapsedMilliseconds);
            }
        }
        catch (Exception ex)
        {
            _logger.LogError(
                ex,
                "Unhandled HTTP exception after {ElapsedMs} ms. Status {StatusCode}.",
                stopwatch.ElapsedMilliseconds,
                context.Response.StatusCode);
            throw;
        }
    }

    private static string ResolveCorrelationId(HttpContext context)
    {
        var supplied = context.Request.Headers[CorrelationHeader].FirstOrDefault();
        if (!string.IsNullOrWhiteSpace(supplied) && supplied.Length <= 128 && supplied.All(IsSafeCorrelationCharacter))
            return supplied;

        return Guid.NewGuid().ToString("N");
    }

    private static bool IsSafeCorrelationCharacter(char value) =>
        char.IsLetterOrDigit(value) || value is '-' or '_' or '.';
}
