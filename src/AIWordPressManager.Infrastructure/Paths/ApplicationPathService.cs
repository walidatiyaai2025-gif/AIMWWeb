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
            ?? configuration["ASPNETCORE_ENVIRONMENT"]
            ?? Environment.GetEnvironmentVariable("DOTNET_ENVIRONMENT")
            ?? Environment.GetEnvironmentVariable("ASPNETCORE_ENVIRONMENT")
            ?? "Production";
    }

    public string GetApplicationDataDirectory()
    {
        var path = Path.Combine(GetStorageRoot(), "Data");
        EnsureDirectory(path);
        MigrateLegacyDevelopmentDirectory("Data", path);
        return path;
    }

    public string GetDatabasePath()
    {
        var fileName = IsDevelopment
            ? "AIWordPressManager.Development.db"
            : "AIWordPressManager.db";
        return Path.Combine(GetApplicationDataDirectory(), fileName);
    }

    public string GetLogsDirectory() => GetSiblingDirectory("Logs");
    public string GetScreenshotsDirectory() => GetSiblingDirectory("Screenshots");
    public string GetBackupsDirectory() => GetSiblingDirectory("Backups");
    public string GetExportsDirectory() => GetSiblingDirectory("Exports");
    public string GetTemporaryDirectory() => GetSiblingDirectory("Temp");

    private bool IsDevelopment =>
        string.Equals(_environmentName, "Development", StringComparison.OrdinalIgnoreCase);

    private string GetStorageRoot()
    {
        if (_portableMode)
            return AppContext.BaseDirectory;

        var root = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "AIWordPressManager");

        return IsDevelopment
            ? Path.Combine(root, "Development")
            : root;
    }

    private string GetSiblingDirectory(string name)
    {
        var path = EnsureDirectory(Path.Combine(GetStorageRoot(), name));
        MigrateLegacyDevelopmentDirectory(name, path);
        return path;
    }

    private void MigrateLegacyDevelopmentDirectory(string name, string destination)
    {
        if (_portableMode || !IsDevelopment)
            return;

        var legacy = Path.Combine(AppContext.BaseDirectory, name);
        if (!Directory.Exists(legacy))
            return;

        try
        {
            foreach (var sourceFile in Directory.EnumerateFiles(legacy, "*", SearchOption.AllDirectories))
            {
                var relative = Path.GetRelativePath(legacy, sourceFile);
                var destinationFile = Path.Combine(destination, relative);
                if (File.Exists(destinationFile))
                    continue;

                var destinationDirectory = Path.GetDirectoryName(destinationFile);
                if (!string.IsNullOrWhiteSpace(destinationDirectory))
                    Directory.CreateDirectory(destinationDirectory);

                try
                {
                    File.Copy(sourceFile, destinationFile, overwrite: false);
                }
                catch (IOException)
                {
                    // A build/log/database sidecar may still be in use. Migration is
                    // intentionally best-effort and will retry on the next access.
                }
                catch (UnauthorizedAccessException)
                {
                    // Do not block application startup because one legacy artifact
                    // cannot be copied. The stable destination remains authoritative.
                }
            }
        }
        catch (IOException)
        {
            // The legacy tree may change while it is being enumerated.
        }
        catch (UnauthorizedAccessException)
        {
            // A protected legacy subdirectory must not prevent normal startup.
        }
    }

    private static string EnsureDirectory(string path)
    {
        Directory.CreateDirectory(path);
        return path;
    }
}
