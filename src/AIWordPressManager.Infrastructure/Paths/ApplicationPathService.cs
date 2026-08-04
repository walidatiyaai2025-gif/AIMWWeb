using AIWordPressManager.Application.Abstractions;
using Microsoft.Extensions.Configuration;

namespace AIWordPressManager.Infrastructure.Paths;

public sealed class ApplicationPathService : IApplicationPathService
{
    private readonly bool _portableMode;
    private readonly string _environmentName;

    public ApplicationPathService(IConfiguration configuration)
    {
        _portableMode = configuration.GetValue<bool>("Application:PortableMode");
        _environmentName = configuration["DOTNET_ENVIRONMENT"]
            ?? Environment.GetEnvironmentVariable("DOTNET_ENVIRONMENT")
            ?? "Production";
    }

    public string GetApplicationDataDirectory()
    {
        string path;
        if (_portableMode)
        {
            path = Path.Combine(AppContext.BaseDirectory, "Data");
        }
        else if (string.Equals(_environmentName, "Development", StringComparison.OrdinalIgnoreCase))
        {
            path = Path.Combine(AppContext.BaseDirectory, "Data");
        }
        else
        {
            path = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "AIWordPressManager",
                "Data");
        }

        return EnsureDirectory(path);
    }

    public string GetDatabasePath()
    {
        var fileName = string.Equals(_environmentName, "Development", StringComparison.OrdinalIgnoreCase)
            ? "AIWordPressManager.Development.db"
            : "AIWordPressManager.db";
        return Path.Combine(GetApplicationDataDirectory(), fileName);
    }

    public string GetLogsDirectory() => GetSiblingDirectory("Logs");
    public string GetScreenshotsDirectory() => GetSiblingDirectory("Screenshots");
    public string GetBackupsDirectory() => GetSiblingDirectory("Backups");
    public string GetExportsDirectory() => GetSiblingDirectory("Exports");
    public string GetTemporaryDirectory() => GetSiblingDirectory("Temp");

    private string GetSiblingDirectory(string name)
    {
        var root = _portableMode || string.Equals(_environmentName, "Development", StringComparison.OrdinalIgnoreCase)
            ? AppContext.BaseDirectory
            : Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AIWordPressManager");
        return EnsureDirectory(Path.Combine(root, name));
    }

    private static string EnsureDirectory(string path)
    {
        Directory.CreateDirectory(path);
        return path;
    }
}
