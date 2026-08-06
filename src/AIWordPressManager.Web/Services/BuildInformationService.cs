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
        var informational = assembly
            .GetCustomAttribute<AssemblyInformationalVersionAttribute>()?
            .InformationalVersion;
        var metadata = assembly.GetCustomAttributes<AssemblyMetadataAttribute>().ToList();

        var version = assemblyName.Version?.ToString(3) ?? "0.0.0";
        var branch = FirstNotEmpty(
            Environment.GetEnvironmentVariable("GITHUB_HEAD_REF"),
            Environment.GetEnvironmentVariable("GITHUB_REF_NAME"),
            Environment.GetEnvironmentVariable("BUILD_SOURCEBRANCHNAME"),
            TryGit("rev-parse --abbrev-ref HEAD"),
            metadata.FirstOrDefault(x => x.Key == "GitBranch")?.Value,
            "unknown");
        var commit = FirstNotEmpty(
            Environment.GetEnvironmentVariable("GITHUB_SHA"),
            Environment.GetEnvironmentVariable("BUILD_SOURCEVERSION"),
            TryGit("rev-parse --short HEAD"),
            ExtractCommit(informational),
            "unknown");

        if (commit.Length > 12) commit = commit[..12];

        var assemblyPath = assembly.Location;
        var buildTimeUtc = File.Exists(assemblyPath)
            ? File.GetLastWriteTimeUtc(assemblyPath)
            : DateTime.UtcNow;

        return new BuildInformation(
            version,
            informational ?? version,
            branch,
            commit,
            buildTimeUtc,
            assemblyName.Name ?? "AIWordPressManager.Web");
    }

    private static string TryGit(string arguments)
    {
        foreach (var directory in GitWorkingDirectories())
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
                    WorkingDirectory = directory
                });
                if (process is null) continue;

                var output = process.StandardOutput.ReadToEnd().Trim();
                process.WaitForExit(1500);
                if (process.ExitCode == 0 && !string.IsNullOrWhiteSpace(output))
                {
                    return output;
                }
            }
            catch
            {
                // Try the next candidate directory.
            }
        }

        return string.Empty;
    }

    private static IEnumerable<string> GitWorkingDirectories()
    {
        var candidates = new[]
        {
            Directory.GetCurrentDirectory(),
            AppContext.BaseDirectory
        };

        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var candidate in candidates)
        {
            var current = new DirectoryInfo(candidate);
            while (current is not null)
            {
                if (seen.Add(current.FullName))
                {
                    yield return current.FullName;
                }

                current = current.Parent;
            }
        }
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
