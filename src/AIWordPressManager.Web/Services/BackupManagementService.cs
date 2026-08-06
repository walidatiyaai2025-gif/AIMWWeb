using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;

namespace AIWordPressManager.Web.Services;

public sealed class BackupManagementService
{
    private readonly object _sync = new();
    private readonly string _dataDirectory;
    private readonly string _backupDirectory;
    private readonly string _historyPath;

    public BackupManagementService()
    {
        var root = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AIWordPressManager");
        _dataDirectory = Path.Combine(root, "Data");
        _backupDirectory = Path.Combine(root, "Backups");
        _historyPath = Path.Combine(_backupDirectory, "backup-history.jsonl");
        Directory.CreateDirectory(_dataDirectory);
        Directory.CreateDirectory(_backupDirectory);
    }

    public string BackupDirectory => _backupDirectory;
    public string DataDirectory => _dataDirectory;

    public IReadOnlyList<BackupFileInfo> GetBackups()
    {
        lock (_sync)
        {
            return Directory.EnumerateFiles(_backupDirectory, "AIWM-Backup-*.zip", SearchOption.TopDirectoryOnly)
                .Select(CreateInfo)
                .OrderByDescending(x => x.CreatedAtUtc)
                .ToList();
        }
    }

    public IReadOnlyList<BackupAuditEntry> GetHistory(int take = 100)
    {
        lock (_sync)
        {
            if (!File.Exists(_historyPath)) return Array.Empty<BackupAuditEntry>();
            return File.ReadLines(_historyPath)
                .TakeLast(Math.Clamp(take, 1, 500))
                .Select(TryDeserializeAudit)
                .Where(x => x is not null)
                .Cast<BackupAuditEntry>()
                .OrderByDescending(x => x.TimestampUtc)
                .ToList();
        }
    }

    public BackupFileInfo CreateBackup(string? note = null)
    {
        lock (_sync)
        {
            var now = DateTime.UtcNow;
            var fileName = $"AIWM-Backup-{now:yyyyMMdd-HHmmss}-{Guid.NewGuid():N}.zip";
            var target = Path.Combine(_backupDirectory, fileName);
            var sources = Directory.EnumerateFiles(_dataDirectory, "*.db", SearchOption.TopDirectoryOnly).ToList();
            if (sources.Count == 0) throw new InvalidOperationException("No application databases were found to back up.");

            var manifest = new BackupManifest(
                2,
                now,
                string.IsNullOrWhiteSpace(note) ? null : note.Trim(),
                Environment.MachineName,
                sources.Select(x => new BackupManifestFile(Path.GetFileName(x), new FileInfo(x).Length, ComputeSha256(x))).ToList());

            try
            {
                using (var archive = ZipFile.Open(target, ZipArchiveMode.Create))
                {
                    foreach (var source in sources)
                    {
                        archive.CreateEntryFromFile(source, $"data/{Path.GetFileName(source)}", CompressionLevel.Optimal);
                        AddOptionalSidecar(archive, source + "-wal", $"data/{Path.GetFileName(source)}-wal");
                        AddOptionalSidecar(archive, source + "-shm", $"data/{Path.GetFileName(source)}-shm");
                    }

                    var manifestEntry = archive.CreateEntry("manifest.json", CompressionLevel.Optimal);
                    using var writer = new StreamWriter(manifestEntry.Open());
                    writer.Write(JsonSerializer.Serialize(manifest, JsonOptions));
                }

                var info = CreateInfo(target);
                WriteAudit("Create", info.FileName, true, $"Created verified backup containing {info.DatabaseCount} database file(s).");
                return info;
            }
            catch (Exception ex)
            {
                if (File.Exists(target)) File.Delete(target);
                WriteAudit("Create", fileName, false, ex.Message);
                throw;
            }
        }
    }

    public BackupVerificationResult Verify(string fileName)
    {
        lock (_sync)
        {
            var result = InspectInternal(fileName);
            WriteAudit("Verify", fileName, result.IsValid, result.Message);
            return new(result.IsValid, result.Message, result.DatabaseCount, result.CreatedAtUtc);
        }
    }

    public BackupInspectionResult Inspect(string fileName)
    {
        lock (_sync)
        {
            var result = InspectInternal(fileName);
            WriteAudit("Inspect", fileName, result.IsValid, result.Message);
            return result;
        }
    }

    public RestoreReadinessResult CheckRestoreReadiness(string fileName)
    {
        lock (_sync)
        {
            var inspection = InspectInternal(fileName);
            var checks = new List<RestoreReadinessCheck>
            {
                new("Archive integrity", "سلامة ملف النسخة", inspection.IsValid, inspection.Message),
                new("Database content", "محتوى قواعد البيانات", inspection.DatabaseCount > 0, inspection.DatabaseCount > 0 ? $"{inspection.DatabaseCount} database file(s) found." : "No database files found."),
                new("Manifest compatibility", "توافق ملف التعريف", inspection.ManifestVersion is 1 or 2, $"Manifest version: {inspection.ManifestVersion}"),
                new("Free disk space", "المساحة الحرة", HasEnoughFreeSpace(inspection.UncompressedBytes), $"Required estimate: {FormatBytes(inspection.UncompressedBytes * 2)}"),
                new("Application state", "حالة التطبيق", false, "Restore must run while the web application is stopped to avoid replacing open SQLite files.")
            };

            var ready = checks.All(x => x.Passed);
            var message = ready
                ? "The backup passed all restore readiness checks."
                : "Restore is blocked until all checks pass. Stop the application before performing the offline restore.";
            WriteAudit("Restore preflight", fileName, ready, message);
            return new(fileName, ready, message, checks, inspection);
        }
    }

    public void Delete(string fileName)
    {
        lock (_sync)
        {
            var path = ResolvePath(fileName);
            if (File.Exists(path))
            {
                File.Delete(path);
                WriteAudit("Delete", fileName, true, "Backup deleted.");
            }
        }
    }

    public string GetDownloadPath(string fileName)
    {
        var path = ResolvePath(fileName);
        if (!File.Exists(path)) throw new FileNotFoundException("Backup file was not found.", fileName);
        return path;
    }

    private BackupInspectionResult InspectInternal(string fileName)
    {
        var path = ResolvePath(fileName);
        if (!File.Exists(path)) return BackupInspectionResult.Invalid(fileName, "Backup file was not found.");

        try
        {
            using var archive = ZipFile.OpenRead(path);
            var manifestEntry = archive.GetEntry("manifest.json");
            if (manifestEntry is null) return BackupInspectionResult.Invalid(fileName, "The backup manifest is missing.");

            using var reader = new StreamReader(manifestEntry.Open());
            var manifest = JsonSerializer.Deserialize<BackupManifest>(reader.ReadToEnd(), JsonOptions);
            if (manifest is null || manifest.Version is < 1 or > 2) return BackupInspectionResult.Invalid(fileName, "The backup manifest is invalid or unsupported.");

            var databaseEntries = archive.Entries
                .Where(x => x.FullName.StartsWith("data/", StringComparison.OrdinalIgnoreCase) && x.Name.EndsWith(".db", StringComparison.OrdinalIgnoreCase))
                .ToList();
            if (databaseEntries.Count == 0) return BackupInspectionResult.Invalid(fileName, "The archive does not contain database files.", manifest.Version, manifest.CreatedAtUtc);

            var issues = new List<string>();
            foreach (var declared in manifest.Files)
            {
                var entry = databaseEntries.FirstOrDefault(x => string.Equals(x.Name, declared.Name, StringComparison.OrdinalIgnoreCase));
                if (entry is null) issues.Add($"Missing database entry: {declared.Name}");
                else if (declared.SizeBytes > 0 && entry.Length != declared.SizeBytes) issues.Add($"Size mismatch: {declared.Name}");
            }

            var valid = issues.Count == 0;
            return new BackupInspectionResult(
                fileName,
                valid,
                valid ? "Backup inspection completed successfully." : string.Join("; ", issues),
                manifest.Version,
                manifest.CreatedAtUtc,
                manifest.Note,
                manifest.MachineName,
                databaseEntries.Count,
                archive.Entries.Sum(x => x.Length),
                manifest.Files);
        }
        catch (InvalidDataException ex)
        {
            return BackupInspectionResult.Invalid(fileName, ex.Message);
        }
        catch (JsonException ex)
        {
            return BackupInspectionResult.Invalid(fileName, ex.Message);
        }
    }

    private BackupFileInfo CreateInfo(string path)
    {
        var file = new FileInfo(path);
        var inspection = InspectInternal(file.Name);
        return new(file.Name, file.Length, file.CreationTimeUtc, file.LastWriteTimeUtc, inspection.IsValid, inspection.DatabaseCount, ComputeSha256(path), inspection.Note);
    }

    private bool HasEnoughFreeSpace(long uncompressedBytes)
    {
        try
        {
            var root = Path.GetPathRoot(_dataDirectory);
            if (string.IsNullOrWhiteSpace(root)) return false;
            var drive = new DriveInfo(root);
            return drive.AvailableFreeSpace > Math.Max(uncompressedBytes * 2, 100 * 1024 * 1024);
        }
        catch { return false; }
    }

    private void WriteAudit(string action, string fileName, bool succeeded, string message)
    {
        var entry = new BackupAuditEntry(DateTime.UtcNow, action, fileName, succeeded, message);
        File.AppendAllText(_historyPath, JsonSerializer.Serialize(entry, JsonOptions) + Environment.NewLine);
    }

    private static BackupAuditEntry? TryDeserializeAudit(string line)
    {
        try { return JsonSerializer.Deserialize<BackupAuditEntry>(line, JsonOptions); }
        catch { return null; }
    }

    private string ResolvePath(string fileName)
    {
        var safeName = Path.GetFileName(fileName);
        if (!string.Equals(safeName, fileName, StringComparison.Ordinal) || !safeName.EndsWith(".zip", StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("Invalid backup file name.");
        var full = Path.GetFullPath(Path.Combine(_backupDirectory, safeName));
        var root = Path.GetFullPath(_backupDirectory) + Path.DirectorySeparatorChar;
        if (!full.StartsWith(root, StringComparison.OrdinalIgnoreCase)) throw new InvalidOperationException("Invalid backup path.");
        return full;
    }

    private static void AddOptionalSidecar(ZipArchive archive, string source, string entryName)
    {
        if (File.Exists(source)) archive.CreateEntryFromFile(source, entryName, CompressionLevel.Optimal);
    }

    private static string ComputeSha256(string path)
    {
        using var stream = File.OpenRead(path);
        return Convert.ToHexString(SHA256.HashData(stream));
    }

    private static string FormatBytes(long value) => value switch
    {
        >= 1073741824 => $"{value / 1073741824d:0.00} GB",
        >= 1048576 => $"{value / 1048576d:0.00} MB",
        >= 1024 => $"{value / 1024d:0.0} KB",
        _ => $"{value} B"
    };

    private static readonly JsonSerializerOptions JsonOptions = new() { PropertyNameCaseInsensitive = true, WriteIndented = true };
}

public sealed record BackupFileInfo(string FileName, long SizeBytes, DateTime CreatedAtUtc, DateTime ModifiedAtUtc, bool IsValid, int DatabaseCount, string Sha256, string? Note);
public sealed record BackupVerificationResult(bool IsValid, string Message, int DatabaseCount, DateTime? CreatedAtUtc);
public sealed record BackupManifest(int Version, DateTime CreatedAtUtc, string? Note, string MachineName, IReadOnlyList<BackupManifestFile> Files);
public sealed record BackupManifestFile(string Name, long SizeBytes, string? Sha256 = null);
public sealed record BackupAuditEntry(DateTime TimestampUtc, string Action, string FileName, bool Succeeded, string Message);
public sealed record RestoreReadinessCheck(string Name, string NameAr, bool Passed, string Message);
public sealed record RestoreReadinessResult(string FileName, bool IsReady, string Message, IReadOnlyList<RestoreReadinessCheck> Checks, BackupInspectionResult Inspection);
public sealed record BackupInspectionResult(string FileName, bool IsValid, string Message, int ManifestVersion, DateTime? CreatedAtUtc, string? Note, string? MachineName, int DatabaseCount, long UncompressedBytes, IReadOnlyList<BackupManifestFile> Files)
{
    public static BackupInspectionResult Invalid(string fileName, string message, int manifestVersion = 0, DateTime? createdAtUtc = null) =>
        new(fileName, false, message, manifestVersion, createdAtUtc, null, null, 0, 0, Array.Empty<BackupManifestFile>());
}
