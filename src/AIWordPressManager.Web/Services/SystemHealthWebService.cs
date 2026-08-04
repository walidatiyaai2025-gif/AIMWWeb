using AIWordPressManager.Application.Abstractions;
using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Web.Services;

public sealed class SystemHealthWebService(
    IApplicationPathService paths,
    IWebHostEnvironment environment,
    ILogger<SystemHealthWebService> logger)
{
    public async Task<SystemHealthSnapshot> CheckAsync(CancellationToken cancellationToken = default)
    {
        var checks = new List<SystemHealthCheck>();

        checks.Add(new(
            "application",
            true,
            environment.EnvironmentName,
            $".NET {Environment.Version} | {Environment.OSVersion}"));

        var dataDirectory = paths.GetApplicationDataDirectory();
        try
        {
            Directory.CreateDirectory(dataDirectory);
            var probe = Path.Combine(dataDirectory, $"health-{Guid.NewGuid():N}.tmp");
            await File.WriteAllTextAsync(probe, "ok", cancellationToken);
            File.Delete(probe);
            checks.Add(new("storage", true, dataDirectory, "Read/write access is available."));
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "Application storage health check failed for {Directory}", dataDirectory);
            checks.Add(new("storage", false, dataDirectory, ex.Message));
        }

        var databasePath = paths.GetDatabasePath();
        try
        {
            var builder = new SqliteConnectionStringBuilder
            {
                DataSource = databasePath,
                Mode = SqliteOpenMode.ReadWriteCreate,
                Cache = SqliteCacheMode.Shared
            };

            await using var connection = new SqliteConnection(builder.ConnectionString);
            await connection.OpenAsync(cancellationToken);
            await using var command = connection.CreateCommand();
            command.CommandText = "PRAGMA quick_check;";
            var result = Convert.ToString(await command.ExecuteScalarAsync(cancellationToken));
            var healthy = string.Equals(result, "ok", StringComparison.OrdinalIgnoreCase);
            var size = File.Exists(databasePath) ? new FileInfo(databasePath).Length : 0;
            checks.Add(new("sqlite", healthy, databasePath, $"quick_check: {result}; size: {size:N0} bytes"));
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "SQLite health check failed for {DatabasePath}", databasePath);
            checks.Add(new("sqlite", false, databasePath, ex.Message));
        }

        var logsDirectory = paths.GetLogsDirectory();
        checks.Add(new("logs", Directory.Exists(logsDirectory), logsDirectory,
            Directory.Exists(logsDirectory) ? "Logs directory is available." : "Logs directory has not been created yet."));

        return new SystemHealthSnapshot(
            checks.All(x => x.IsHealthy),
            DateTimeOffset.UtcNow,
            typeof(Program).Assembly.GetName().Version?.ToString(3) ?? "unknown",
            checks);
    }
}

public sealed record SystemHealthSnapshot(
    bool IsHealthy,
    DateTimeOffset CheckedAtUtc,
    string Version,
    IReadOnlyList<SystemHealthCheck> Checks);

public sealed record SystemHealthCheck(
    string Key,
    bool IsHealthy,
    string Target,
    string Details);
