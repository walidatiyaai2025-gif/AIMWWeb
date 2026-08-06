using System.Text.RegularExpressions;

namespace AIWordPressManager.Web.Services;

public sealed class ReleaseNotesService
{
    private readonly string _path;
    private readonly object _sync = new();
    private DateTime _lastWriteUtc;
    private IReadOnlyList<ReleaseNote> _cache = Array.Empty<ReleaseNote>();

    public ReleaseNotesService(IWebHostEnvironment environment)
    {
        _path = Path.Combine(environment.ContentRootPath, "RELEASE_NOTES.md");
    }

    public IReadOnlyList<ReleaseNote> GetAll()
    {
        lock (_sync)
        {
            if (!File.Exists(_path)) return Array.Empty<ReleaseNote>();
            var lastWrite = File.GetLastWriteTimeUtc(_path);
            if (_cache.Count > 0 && lastWrite == _lastWriteUtc) return _cache;
            _cache = Parse(File.ReadAllLines(_path));
            _lastWriteUtc = lastWrite;
            return _cache;
        }
    }

    public ReleaseNote? GetCurrent(string version)
    {
        var normalized = version.Trim().TrimStart('v', 'V');
        return GetAll().FirstOrDefault(x => string.Equals(x.Version, normalized, StringComparison.OrdinalIgnoreCase));
    }

    private static IReadOnlyList<ReleaseNote> Parse(IReadOnlyList<string> lines)
    {
        var releases = new List<ReleaseNote>();
        string? version = null;
        DateOnly? date = null;
        string? title = null;
        var changes = new List<string>();

        void Flush()
        {
            if (string.IsNullOrWhiteSpace(version)) return;
            releases.Add(new ReleaseNote(version, date, title ?? $"Version {version}", changes.ToArray()));
            changes.Clear();
        }

        foreach (var raw in lines)
        {
            var line = raw.Trim();
            if (line.StartsWith("## ", StringComparison.Ordinal))
            {
                Flush();
                var header = line[3..].Trim();
                var match = Regex.Match(header, @"^v?(?<version>\d+(?:\.\d+){1,3})(?:\s*[-–—]\s*(?<date>\d{4}-\d{2}-\d{2}))?(?:\s*[-–—:]\s*(?<title>.+))?$", RegexOptions.CultureInvariant);
                if (match.Success)
                {
                    version = match.Groups["version"].Value;
                    date = DateOnly.TryParse(match.Groups["date"].Value, out var parsed) ? parsed : null;
                    title = match.Groups["title"].Success ? match.Groups["title"].Value.Trim() : null;
                }
                else
                {
                    version = header.TrimStart('v', 'V');
                    date = null;
                    title = null;
                }
                continue;
            }

            if (version is not null && (line.StartsWith("- ") || line.StartsWith("* ")))
                changes.Add(line[2..].Trim());
        }

        Flush();
        return releases;
    }
}

public sealed record ReleaseNote(string Version, DateOnly? Date, string Title, IReadOnlyList<string> Changes);
