using System.Diagnostics;
using System.Reflection;

namespace AIWordPressManager.Web.Services;

public sealed class BuildInformationService
{
    private readonly Lazy<BuildInformation> _current = new(Load);

    public BuildInformation Current => _current.Value;

    private static BuildInformation Load()
    {
        var assembly = typeof(BuildInformationService).Assembly;
        var assemblyName = assembly.GetName();
        var assemblyPath = assembly.Location;
        var informational = assembly
            .GetCustomAttribute<AssemblyInformationalVersionAttribute>()?
            .InformationalVersion;

        var productVersion = !string.IsNullOrWhiteSpace(assemblyPath) && File.Exists(assemblyPath)
            ? FileVersionInfo.GetVersionInfo(assemblyPath).ProductVersion
            : null;

        var version = NormalizeVersion(productVersion)
            ?? NormalizeVersion(informational)
            ?? assemblyName.Version?.ToString(3)
            ?? "0.0.0";

        var branch = FirstNotEmpty(
            Environment.GetEnvironmentVariable("GITHUB_REF_NAME"),
            Environment.GetEnvironmentVariable("BUILD_SOURCEBRANCHNAME"),
            TryGit("rev-parse --abbrev-ref HEAD"),
            "unknown");
        var commit = FirstNotEmpty(
            Environment.GetEnvironmentVariable("GITHUB_SHA"),
            Environment.GetEnvironmentVariable("BUILD_SOURCEVERSION"),
            TryGit("rev-parse --short HEAD"),
            ExtractCommit(productVersion),
            ExtractCommit(informational),
            "unknown");

        if (commit.Length > 12) commit = commit[..12];

        var buildTimeUtc = File.Exists(assemblyPath)
            ? File.GetLastWriteTimeUtc(assemblyPath)
            : DateTime.UtcNow;

        return new BuildInformation(
            version,
            informational ?? productVersion ?? version,
            branch,
            commit,
            buildTimeUtc,
            assemblyName.Name ?? "AIWordPressManager.Web");
    }

    private static string? NormalizeVersion(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return null;

        var normalized = value.Trim();
        var plus = normalized.IndexOf('+');
        if (plus >= 0) normalized = normalized[..plus];

        return string.IsNullOrWhiteSpace(normalized) ? null : normalized;
    }

    private static string TryGit(string arguments)
    {
        try
        {
            using var process = Process.Start(new ProcessStartInfo
            {
                FileName = "git",
                Arguments = arguments,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                UseShellExecute = false,
                CreateNoWindow = true,
                WorkingDirectory = FindRepositoryDirectory()
            });
            if (process is null) return string.Empty;
            var output = process.StandardOutput.ReadToEnd().Trim();
            process.WaitForExit(1500);
            return process.ExitCode == 0 ? output : string.Empty;
        }
        catch
        {
            return string.Empty;
        }
    }

    private static string FindRepositoryDirectory()
    {
        var directory = new DirectoryInfo(AppContext.BaseDirectory);
        while (directory is not null)
        {
            if (Directory.Exists(Path.Combine(directory.FullName, ".git")))
                return directory.FullName;
            directory = directory.Parent;
        }

        return AppContext.BaseDirectory;
    }

    private static string ExtractCommit(string? informational)
    {
        if (string.IsNullOrWhiteSpace(informational)) return string.Empty;
        var plus = informational.IndexOf('+');
        return plus >= 0 && plus < informational.Length - 1 ? informational[(plus + 1)..] : string.Empty;
    }

    private static string FirstNotEmpty(params string?[] values) =>
        values.First(value => !string.IsNullOrWhiteSpace(value))!.Trim();
}

public sealed record BuildInformation(
    string Version,
    string InformationalVersion,
    string Branch,
    string Commit,
    DateTime BuildTimeUtc,
    string AssemblyName);
