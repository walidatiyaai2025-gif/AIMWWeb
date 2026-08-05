namespace AIWordPressManager.Web.Services;

public sealed class LogReaderService
{
    private readonly string[] _directories;

    public LogReaderService()
    {
        var local = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AIWordPressManager", "Logs");
        var app = Path.Combine(AppContext.BaseDirectory, "Logs");
        Directory.CreateDirectory(local);
        _directories = new[] { local, app }.Distinct(StringComparer.OrdinalIgnoreCase).ToArray();
    }

    public IReadOnlyList<LogFileInfo> GetFiles()
    {
        return _directories.Where(Directory.Exists)
            .SelectMany(path => Directory.EnumerateFiles(path, "*.*", SearchOption.TopDirectoryOnly))
            .Where(path => path.EndsWith(".log", StringComparison.OrdinalIgnoreCase) || path.EndsWith(".txt", StringComparison.OrdinalIgnoreCase))
            .Select(path => new FileInfo(path))
            .OrderByDescending(x => x.LastWriteTimeUtc)
            .Select(x => new LogFileInfo(x.FullName, x.Name, x.Length, x.LastWriteTimeUtc))
            .ToList();
    }

    public IReadOnlyList<LogLine> Read(string path, int take = 500)
    {
        if (string.IsNullOrWhiteSpace(path)) return Array.Empty<LogLine>();
        var full = Path.GetFullPath(path);
        if (!_directories.Any(root => full.StartsWith(Path.GetFullPath(root), StringComparison.OrdinalIgnoreCase)))
            throw new InvalidOperationException("The requested log file is outside the allowed log directories.");
        if (!File.Exists(full)) return Array.Empty<LogLine>();

        var lines = File.ReadLines(full).TakeLast(Math.Clamp(take, 50, 5000)).ToArray();
        return lines.Select((text, index) => new LogLine(index + 1, DetectLevel(text), text)).ToList();
    }

    private static string DetectLevel(string value)
    {
        if (value.Contains("critical", StringComparison.OrdinalIgnoreCase) || value.Contains("fatal", StringComparison.OrdinalIgnoreCase)) return "Critical";
        if (value.Contains("error", StringComparison.OrdinalIgnoreCase) || value.Contains("exception", StringComparison.OrdinalIgnoreCase) || value.Contains("fail", StringComparison.OrdinalIgnoreCase)) return "Error";
        if (value.Contains("warn", StringComparison.OrdinalIgnoreCase)) return "Warning";
        if (value.Contains("debug", StringComparison.OrdinalIgnoreCase) || value.Contains("trace", StringComparison.OrdinalIgnoreCase)) return "Debug";
        return "Information";
    }
}

public sealed record LogFileInfo(string Path, string Name, long Size, DateTime LastWriteUtc);
public sealed record LogLine(int Number, string Level, string Text);