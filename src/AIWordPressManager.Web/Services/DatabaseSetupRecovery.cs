using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.Extensions.Configuration;

namespace AIWordPressManager.Web.Services;

public static class DatabaseSetupRecovery
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        WriteIndented = true,
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull
    };

    public static void MarkIncomplete(
        IConfiguration configuration,
        string environmentName,
        ILogger logger)
    {
        var provider = configuration["Database:Provider"] ?? "SQLite";
        var protectedConnectionString = configuration["Database:ProtectedConnectionString"];
        var connectionString = string.IsNullOrWhiteSpace(protectedConnectionString)
            ? configuration["Database:ConnectionString"]
            : null;
        var path = DatabaseSetupService.GetConfigurationPath(environmentName);

        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        var payload = new
        {
            Database = new
            {
                SetupComplete = false,
                Provider = provider,
                ConnectionString = connectionString,
                ProtectedConnectionString = protectedConnectionString,
                ConfiguredAtUtc = DateTime.UtcNow
            }
        };

        var temp = path + ".tmp";
        File.WriteAllText(temp, JsonSerializer.Serialize(payload, JsonOptions));
        File.Move(temp, path, true);

        if (configuration is IConfigurationRoot root)
            root.Reload();

        logger.LogWarning(
            "Database setup was marked incomplete after startup initialization failed. Provider: {Provider}. Connection details were not logged.",
            provider);
    }
}
