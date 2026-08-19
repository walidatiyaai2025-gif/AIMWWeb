using System.Collections.Concurrent;
using System.Reflection;
using System.Text.Json;

namespace AIWordPressManager.Web.Diagnostics;

public sealed class RuntimeFileLoggerProvider : ILoggerProvider, ISupportExternalScope
{
    private readonly RuntimeInspectorOptions _options;
    private readonly ConcurrentDictionary<string, RuntimeFileLogger> _loggers = new(StringComparer.Ordinal);
    private readonly object _writeLock = new();
    private IExternalScopeProvider _scopeProvider = new LoggerExternalScopeProvider();
    private DateOnly _lastCleanupDate;
    private readonly string _applicationVersion;
    private string? _resolvedDirectory;

    public RuntimeFileLoggerProvider(RuntimeInspectorOptions options)
    {
        _options = options;
        _applicationVersion = Assembly.GetEntryAssembly()?
            .GetCustomAttribute<AssemblyInformationalVersionAttribute>()?.InformationalVersion
            ?? Assembly.GetEntryAssembly()?.GetName().Version?.ToString()
            ?? "unknown";
    }

    public ILogger CreateLogger(string categoryName) =>
        _loggers.GetOrAdd(categoryName, category => new RuntimeFileLogger(category, this));

    public void SetScopeProvider(IExternalScopeProvider scopeProvider) =>
        _scopeProvider = scopeProvider ?? new LoggerExternalScopeProvider();

    internal IExternalScopeProvider ScopeProvider => _scopeProvider;

    internal void Write(
        string category,
        LogLevel level,
        EventId eventId,
        string message,
        Exception? exception,
        IReadOnlyDictionary<string, object?> properties)
    {
        if (!_options.Enabled) return;

        try
        {
            var now = DateTimeOffset.Now;
            var payload = new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase)
            {
                ["timestampUtc"] = now.UtcDateTime.ToString("O"),
                ["timestampLocal"] = now.ToString("O"),
                ["level"] = level.ToString(),
                ["category"] = category,
                ["eventId"] = eventId.Id,
                ["eventName"] = eventId.Name,
                ["message"] = RuntimeLogRedactor.Redact(message),
                ["applicationVersion"] = _applicationVersion,
                ["machine"] = Environment.MachineName,
                ["processId"] = Environment.ProcessId,
                ["threadId"] = Environment.CurrentManagedThreadId,
                ["environment"] = Environment.GetEnvironmentVariable("ASPNETCORE_ENVIRONMENT") ?? "Production"
            };

            foreach (var pair in properties)
            {
                if (pair.Key is "{OriginalFormat}") continue;
                payload[pair.Key] = RuntimeLogRedactor.IsSensitiveKey(pair.Key)
                    ? "[REDACTED]"
                    : RuntimeLogRedactor.Redact(Convert.ToString(pair.Value, System.Globalization.CultureInfo.InvariantCulture));
            }

            if (exception is not null)
            {
                payload["exceptionType"] = exception.GetType().FullName;
                payload["exceptionMessage"] = RuntimeLogRedactor.Redact(exception.Message);
                payload["exception"] = RuntimeLogRedactor.Redact(exception.ToString());
                if (exception.InnerException is not null)
                {
                    payload["innerExceptionType"] = exception.InnerException.GetType().FullName;
                    payload["innerExceptionMessage"] = RuntimeLogRedactor.Redact(exception.InnerException.Message);
                }
            }

            var json = JsonSerializer.Serialize(payload);
            WriteLine(now, "aimw", json);
            if (level >= LogLevel.Error || exception is not null)
                WriteLine(now, "errors", json);

            CleanupOldLogsIfNeeded(now);
        }
        catch
        {
            // Diagnostics must never crash the application.
        }
    }

    private void WriteLine(DateTimeOffset now, string prefix, string line)
    {
        var directory = ResolveDirectory();
        var path = Path.Combine(directory, $"{prefix}-{now:yyyyMMdd}.log");

        lock (_writeLock)
        {
            if (File.Exists(path) && new FileInfo(path).Length >= _options.MaxFileSizeBytes)
                path = Path.Combine(directory, $"{prefix}-{now:yyyyMMdd}-{now:HHmmssfff}.log");

            File.AppendAllText(path, line + Environment.NewLine, System.Text.Encoding.UTF8);
        }
    }

    private string ResolveDirectory()
    {
        if (!string.IsNullOrWhiteSpace(_resolvedDirectory)) return _resolvedDirectory;

        var configured = !string.IsNullOrWhiteSpace(_options.LogDirectory)
            ? Environment.ExpandEnvironmentVariables(_options.LogDirectory)
            : Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "AIMWWeb", "Logs");

        try
        {
            Directory.CreateDirectory(configured);
            _resolvedDirectory = configured;
        }
        catch
        {
            var fallback = Path.Combine(AppContext.BaseDirectory, "Logs");
            Directory.CreateDirectory(fallback);
            _resolvedDirectory = fallback;
        }

        return _resolvedDirectory;
    }

    private void CleanupOldLogsIfNeeded(DateTimeOffset now)
    {
        var today = DateOnly.FromDateTime(now.Date);
        if (_lastCleanupDate == today) return;
        _lastCleanupDate = today;

        if (_options.RetainedDays <= 0) return;
        var cutoff = now.AddDays(-_options.RetainedDays);
        var directory = ResolveDirectory();
        if (!Directory.Exists(directory)) return;

        foreach (var file in Directory.EnumerateFiles(directory, "*.log", SearchOption.TopDirectoryOnly))
        {
            try
            {
                if (File.GetLastWriteTimeUtc(file) < cutoff.UtcDateTime)
                    File.Delete(file);
            }
            catch
            {
                // Best effort retention cleanup.
            }
        }
    }

    public void Dispose() => _loggers.Clear();

    private sealed class RuntimeFileLogger : ILogger
    {
        private readonly string _category;
        private readonly RuntimeFileLoggerProvider _provider;

        public RuntimeFileLogger(string category, RuntimeFileLoggerProvider provider)
        {
            _category = category;
            _provider = provider;
        }

        public IDisposable? BeginScope<TState>(TState state) where TState : notnull =>
            _provider.ScopeProvider.Push(state);

        public bool IsEnabled(LogLevel logLevel) => logLevel != LogLevel.None;

        public void Log<TState>(
            LogLevel logLevel,
            EventId eventId,
            TState state,
            Exception? exception,
            Func<TState, Exception?, string> formatter)
        {
            if (!IsEnabled(logLevel)) return;

            var properties = new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase);
            AddState(properties, state);
            _provider.ScopeProvider.ForEachScope((scope, target) => AddState(target, scope), properties);

            _provider.Write(_category, logLevel, eventId, formatter(state, exception), exception, properties);
        }

        private static void AddState<TState>(Dictionary<string, object?> target, TState state)
        {
            if (state is IEnumerable<KeyValuePair<string, object?>> pairs)
            {
                foreach (var pair in pairs)
                    target[pair.Key] = pair.Value;
                return;
            }

            if (state is not null)
                target["scope"] = state.ToString();
        }
    }
}
