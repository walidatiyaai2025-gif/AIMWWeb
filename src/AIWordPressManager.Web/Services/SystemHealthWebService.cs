using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Persistence;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class SystemHealthWebService(
    IApplicationPathService paths,
    IWebHostEnvironment environment,
    IConfiguration configuration,
    AppDbContext dbContext,
    ISecretProtectionService secretProtectionService,
    IWordPressConnectionTester connectionTester,
    IEnumerable<IAIProvider> aiProviders,
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

        await AddWordPressChecksAsync(checks, cancellationToken);
        AddAIProviderChecks(checks);

        return new SystemHealthSnapshot(
            checks.All(x => x.IsHealthy),
            DateTimeOffset.UtcNow,
            typeof(Program).Assembly.GetName().Version?.ToString(3) ?? "unknown",
            checks);
    }

    private async Task AddWordPressChecksAsync(List<SystemHealthCheck> checks, CancellationToken cancellationToken)
    {
        List<AIWordPressManager.Domain.Entities.Site> sites;
        try
        {
            sites = await dbContext.Sites.AsNoTracking()
                .Where(x => !x.IsDeleted)
                .OrderBy(x => x.Name)
                .ToListAsync(cancellationToken);
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "Unable to load WordPress sites for health checks.");
            checks.Add(new("wordpress", false, "configured-sites", $"Unable to load configured sites: {ex.Message}"));
            return;
        }

        if (sites.Count == 0)
        {
            checks.Add(new("wordpress", true, "configured-sites", "No WordPress sites are configured yet."));
            return;
        }

        var credentials = await dbContext.SiteCredentials.AsNoTracking()
            .ToDictionaryAsync(x => x.SiteId, cancellationToken);

        foreach (var site in sites)
        {
            if (!credentials.TryGetValue(site.Id, out var credential))
            {
                checks.Add(new($"wordpress:{site.Id:N}", false, site.SiteUrl, $"{site.Name}: credentials are not configured."));
                continue;
            }

            try
            {
                var password = await secretProtectionService.UnprotectAsync(credential.ProtectedApplicationPassword, cancellationToken);
                var result = await connectionTester.TestAsync(
                    new WordPressConnectionRequest(site.SiteUrl, credential.UserName, password),
                    cancellationToken);

                checks.Add(new(
                    $"wordpress:{site.Id:N}",
                    result.IsSuccess,
                    site.SiteUrl,
                    $"{site.Name}: {result.Message}{(string.IsNullOrWhiteSpace(result.Diagnostics) ? string.Empty : $" | {result.Diagnostics}")}"));
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                throw;
            }
            catch (Exception ex)
            {
                logger.LogWarning(ex, "WordPress health check failed for site {SiteId} ({SiteName}).", site.Id, site.Name);
                checks.Add(new($"wordpress:{site.Id:N}", false, site.SiteUrl, $"{site.Name}: {ex.Message}"));
            }
        }
    }

    private void AddAIProviderChecks(List<SystemHealthCheck> checks)
    {
        foreach (var provider in aiProviders)
        {
            var (configured, details) = provider.Name switch
            {
                "OpenAI" => HasValue("AI:OpenAI:ApiKey")
                    ? (true, "API key is configured.")
                    : (false, "API key is not configured."),
                "Gemini" => HasValue("AI:Gemini:ApiKey")
                    ? (true, "API key is configured.")
                    : (false, "API key is not configured."),
                "Puter" => HasValue("AI:Puter:Endpoint")
                    ? (true, HasValue("AI:Puter:Token") ? "Endpoint and token are configured." : "Endpoint is configured; token is optional/not configured.")
                    : (false, "Endpoint is not configured."),
                _ => (true, "Provider is registered; no configuration rule is defined.")
            };

            checks.Add(new($"ai:{provider.Name.ToLowerInvariant()}", configured, provider.Name, details));
        }
    }

    private bool HasValue(string key) => !string.IsNullOrWhiteSpace(configuration[key]);
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
