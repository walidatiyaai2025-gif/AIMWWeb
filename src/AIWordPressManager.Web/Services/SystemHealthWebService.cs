using System.Data;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Persistence;
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
    CurrentUserContext currentUser,
    ILogger<SystemHealthWebService> logger)
{
    private const string ManagedStorageTarget = "managed-application-data";
    private const string ManagedLogsTarget = "managed-application-logs";

    public async Task<SystemHealthSnapshot> CheckAsync(CancellationToken cancellationToken = default)
    {
        var ownerUserId = currentUser.RequirePermission(ApplicationPermissionCatalog.OperationsView);
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
            checks.Add(new("storage", true, ManagedStorageTarget, "Managed application storage read/write access is available."));
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "Application storage health check failed for {Directory}", dataDirectory);
            checks.Add(new("storage", false, ManagedStorageTarget, SanitizedFailure("Application storage", ex)));
        }

        checks.Add(await CheckDatabaseAsync(cancellationToken));

        var logsDirectory = paths.GetLogsDirectory();
        var logsAvailable = Directory.Exists(logsDirectory);
        checks.Add(new(
            "logs",
            logsAvailable,
            ManagedLogsTarget,
            logsAvailable ? "Managed application logging storage is available." : "Managed application logging storage is not available yet."));

        await AddWordPressChecksAsync(checks, ownerUserId, cancellationToken);
        AddAIProviderChecks(checks);

        return new SystemHealthSnapshot(
            checks.All(x => x.IsHealthy),
            DateTimeOffset.UtcNow,
            typeof(Program).Assembly.GetName().Version?.ToString(3) ?? "unknown",
            checks);
    }

    private async Task<SystemHealthCheck> CheckDatabaseAsync(CancellationToken cancellationToken)
    {
        var provider = ResolveDatabaseProvider();
        if (!configuration.GetValue<bool>("Database:SetupComplete"))
            return new("database", false, provider, "Database setup is incomplete.");

        try
        {
            if (provider.Equals("SQLite", StringComparison.OrdinalIgnoreCase))
                return await CheckSqliteAsync(provider, cancellationToken);

            var healthy = await dbContext.Database.CanConnectAsync(cancellationToken);
            return new(
                "database",
                healthy,
                provider,
                healthy
                    ? $"Connection check succeeded for the configured {provider} provider."
                    : $"Connection check failed for the configured {provider} provider.");
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "Configured database health check failed for provider {Provider}", provider);
            return new("database", false, provider, SanitizedFailure($"Configured {provider} database", ex));
        }
    }

    private async Task<SystemHealthCheck> CheckSqliteAsync(string provider, CancellationToken cancellationToken)
    {
        var connection = dbContext.Database.GetDbConnection();
        var openedHere = connection.State != ConnectionState.Open;
        try
        {
            if (openedHere)
                await dbContext.Database.OpenConnectionAsync(cancellationToken);

            await using var command = connection.CreateCommand();
            command.CommandText = "PRAGMA quick_check;";
            var result = Convert.ToString(await command.ExecuteScalarAsync(cancellationToken));
            var healthy = string.Equals(result, "ok", StringComparison.OrdinalIgnoreCase);
            var dataSource = connection.DataSource;
            var size = !string.IsNullOrWhiteSpace(dataSource) && File.Exists(dataSource)
                ? new FileInfo(dataSource).Length
                : 0;

            return new("database", healthy, provider, $"quick_check: {result}; size: {size:N0} bytes");
        }
        finally
        {
            if (openedHere)
                await dbContext.Database.CloseConnectionAsync();
        }
    }

    private string ResolveDatabaseProvider()
    {
        var configured = configuration["Database:Provider"]?.Trim();
        if (!string.IsNullOrWhiteSpace(configured))
            return configured;

        var efProvider = dbContext.Database.ProviderName ?? string.Empty;
        if (efProvider.Contains("Sqlite", StringComparison.OrdinalIgnoreCase)) return "SQLite";
        if (efProvider.Contains("SqlServer", StringComparison.OrdinalIgnoreCase)) return "SqlServer";
        if (efProvider.Contains("Npgsql", StringComparison.OrdinalIgnoreCase)) return "PostgreSQL";
        if (efProvider.Contains("MySql", StringComparison.OrdinalIgnoreCase)) return "MySQL/MariaDB";
        return "Unknown";
    }

    private async Task AddWordPressChecksAsync(
        List<SystemHealthCheck> checks,
        Guid ownerUserId,
        CancellationToken cancellationToken)
    {
        List<AIWordPressManager.Domain.Entities.Site> sites;
        try
        {
            sites = await dbContext.Sites.AsNoTracking()
                .Where(x => x.OwnerUserId == ownerUserId && !x.IsDeleted)
                .OrderBy(x => x.Name)
                .ToListAsync(cancellationToken);
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "Unable to load owner-scoped WordPress sites for health checks for user {OwnerUserId}.", ownerUserId);
            checks.Add(new("wordpress", false, "owned-sites", SanitizedFailure("Owned WordPress site inventory", ex)));
            return;
        }

        if (sites.Count == 0)
        {
            checks.Add(new("wordpress", true, "owned-sites", "No WordPress sites are configured for this account."));
            return;
        }

        var siteIds = sites.Select(x => x.Id).ToArray();
        var credentials = await dbContext.SiteCredentials.AsNoTracking()
            .Where(x => siteIds.Contains(x.SiteId))
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
                logger.LogWarning(ex, "WordPress health check failed for owned site {SiteId} ({SiteName}).", site.Id, site.Name);
                checks.Add(new($"wordpress:{site.Id:N}", false, site.SiteUrl, $"{site.Name}: {SanitizedFailure("WordPress connection", ex)}"));
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

    private static string SanitizedFailure(string component, Exception exception) =>
        $"{component} check failed ({exception.GetType().Name}).";
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
