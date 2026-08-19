using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;

namespace AIWordPressManager.Web.Services;

public sealed class BackupManagementService
{
    private const int CurrentManifestVersion = 3;
    private const string PayloadPrefix = "data/";
    private readonly object _sync = new();
    private readonly string _dataDirectory;
    private readonly string _backupDirectory;
    private readonly string _historyPath;

    public BackupManagementService(string? rootDirectory = null)
    {
        var root = string.IsNullOrWhiteSpace(rootDirectory)
            ? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AIWordPressManager")
            : Path.GetFullPath(rootDirectory);
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
            var sources = EnumerateManagedDataFiles().ToList();
            if (sources.Count == 0 || !sources.Any(x => x.Kind == BackupContentKind.Database))
                throw new InvalidOperationException("No application database was found to back up.");

            var manifestFiles = sources
                .Select(x => new BackupManifestFile(
                    Path.GetFileName(x.SourcePath),
                    new FileInfo(x.SourcePath).Length,
                    ComputeSha256(x.SourcePath),
                    x.RelativePath,
                    x.Kind.ToString()))
                .ToList();
            var manifest = new BackupManifest(
                CurrentManifestVersion,
                now,
                NormalizeNote(note),
                Environment.MachineName,
                manifestFiles);

            try
            {
                using (var archive = ZipFile.Open(target, ZipArchiveMode.Create))
                {
                    foreach (var source in sources)
                    {
                        archive.CreateEntryFromFile(
                            source.SourcePath,
                            PayloadPrefix + source.RelativePath,
                            CompressionLevel.Optimal);
                    }

                    var manifestEntry = archive.CreateEntry("manifest.json", CompressionLevel.Optimal);
                    using var writer = new StreamWriter(manifestEntry.Open());
                    writer.Write(JsonSerializer.Serialize(manifest, JsonOptions));
                }

                var info = CreateInfo(target);
                if (!info.IsValid)
                    throw new InvalidDataException("The backup failed post-write integrity verification and was discarded.");

                WriteAudit(
                    "Create",
                    info.FileName,
                    true,
                    $"Created verified backup containing {manifestFiles.Count} managed file(s), including {info.DatabaseCount} database file(s).");
                return info;
            }
            catch (Exception ex)
            {
                if (File.Exists(target)) File.Delete(target);
                WriteAudit("Create", fileName, false, SanitizeAuditMessage(ex.Message));
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
                new("Managed data coverage", "تغطية بيانات التطبيق", inspection.Files.Count >= inspection.DatabaseCount && inspection.Files.Count > 0, $"{inspection.Files.Count} managed file(s) declared in the manifest."),
                new("Manifest compatibility", "توافق ملف التعريف", inspection.ManifestVersion is >= 1 and <= CurrentManifestVersion, $"Manifest version: {inspection.ManifestVersion}"),
                new("Free disk space", "المساحة الحرة", HasEnoughFreeSpace(inspection.UncompressedBytes), $"Required estimate: {FormatBytes(inspection.UncompressedBytes * 2)}"),
                new("Application state", "حالة التطبيق", false, "Restore must run while the web application is stopped to avoid replacing open application data files.")
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
            var manifestEntries = archive.Entries
                .Where(x => string.Equals(x.FullName, "manifest.json", StringComparison.OrdinalIgnoreCase))
                .ToList();
            if (manifestEntries.Count != 1)
                return BackupInspectionResult.Invalid(fileName, manifestEntries.Count == 0 ? "The backup manifest is missing." : "The archive contains duplicate backup manifests.");

            using var reader = new StreamReader(manifestEntries[0].Open());
            var manifest = JsonSerializer.Deserialize<BackupManifest>(reader.ReadToEnd(), JsonOptions);
            if (manifest is null || manifest.Version is < 1 or > CurrentManifestVersion || manifest.Files is null)
                return BackupInspectionResult.Invalid(fileName, "The backup manifest is invalid or unsupported.");

            var issues = new List<string>();
            foreach (var entry in archive.Entries.Where(x => !string.IsNullOrEmpty(x.Name)))
            {
                if (string.Equals(entry.FullName, "manifest.json", StringComparison.OrdinalIgnoreCase)) continue;
                if (!entry.FullName.StartsWith(PayloadPrefix, StringComparison.OrdinalIgnoreCase))
                    issues.Add($"Unexpected archive entry: {entry.FullName}");
            }

            var payloadEntries = archive.Entries
                .Where(x => !string.IsNullOrEmpty(x.Name) && x.FullName.StartsWith(PayloadPrefix, StringComparison.OrdinalIgnoreCase))
                .ToList();
            if (payloadEntries.Count == 0)
                return BackupInspectionResult.Invalid(fileName, "The archive does not contain managed data files.", manifest.Version, manifest.CreatedAtUtc);

            var payloadByPath = new Dictionary<string, ZipArchiveEntry>(StringComparer.OrdinalIgnoreCase);
            foreach (var entry in payloadEntries)
            {
                if (!TryNormalizePayloadPath(entry.FullName, out var relativePath))
                {
                    issues.Add($"Unsafe archive entry path: {entry.FullName}");
                    continue;
                }

                if (!payloadByPath.TryAdd(relativePath, entry))
                    issues.Add($"Duplicate archive entry: {relativePath}");
            }

            var declaredPaths = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
            foreach (var declared in manifest.Files)
            {
                if (!TryGetDeclaredRelativePath(manifest.Version, declared, out var relativePath))
                {
                    issues.Add($"Unsafe or missing manifest path: {declared.Name}");
                    continue;
                }

                if (!declaredPaths.Add(relativePath))
                {
                    issues.Add($"Duplicate manifest entry: {relativePath}");
                    continue;
                }

                if (!payloadByPath.TryGetValue(relativePath, out var entry))
                {
                    issues.Add($"Missing archive entry: {relativePath}");
                    continue;
                }

                if (declared.SizeBytes < 0 || entry.Length != declared.SizeBytes)
                    issues.Add($"Size mismatch: {relativePath}");

                if (manifest.Version >= CurrentManifestVersion)
                {
                    if (!Enum.TryParse<BackupContentKind>(declared.Kind, true, out var declaredKind) || declaredKind != DetermineKind(relativePath))
                        issues.Add($"Content kind mismatch: {relativePath}");

                    if (string.IsNullOrWhiteSpace(declared.Sha256))
                    {
                        issues.Add($"Missing SHA-256: {relativePath}");
                    }
                    else
                    {
                        var actualHash = ComputeSha256(entry);
                        if (!string.Equals(actualHash, declared.Sha256.Trim(), StringComparison.OrdinalIgnoreCase))
                            issues.Add($"SHA-256 mismatch: {relativePath}");
                    }
                }
            }

            foreach (var payloadPath in payloadByPath.Keys)
            {
                if (!declaredPaths.Contains(payloadPath))
                    issues.Add($"Undeclared archive entry: {payloadPath}");
            }

            var databaseCount = payloadByPath.Keys.Count(x => x.EndsWith(".db", StringComparison.OrdinalIgnoreCase));
            if (databaseCount == 0) issues.Add("The archive does not contain database files.");

            var valid = issues.Count == 0;
            var verificationMode = manifest.Version >= CurrentManifestVersion
                ? "size and SHA-256 verification"
                : "legacy size/path compatibility verification";
            return new BackupInspectionResult(
                fileName,
                valid,
                valid ? $"Backup inspection completed successfully using {verificationMode}." : string.Join("; ", issues),
                manifest.Version,
                manifest.CreatedAtUtc,
                manifest.Note,
                manifest.MachineName,
                databaseCount,
                payloadEntries.Sum(x => x.Length),
                manifest.Files);
        }
        catch (InvalidDataException ex)
        {
            return BackupInspectionResult.Invalid(fileName, SanitizeAuditMessage(ex.Message));
        }
        catch (JsonException ex)
        {
            return BackupInspectionResult.Invalid(fileName, SanitizeAuditMessage(ex.Message));
        }
    }

    private IEnumerable<ManagedBackupSource> EnumerateManagedDataFiles()
    {
        var dataRoot = Path.GetFullPath(_dataDirectory) + Path.DirectorySeparatorChar;
        foreach (var path in Directory.EnumerateFiles(_dataDirectory, "*", SearchOption.AllDirectories))
        {
            if (IsTransientDataFile(path)) continue;

            var fullPath = Path.GetFullPath(path);
            if (!fullPath.StartsWith(dataRoot, StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("Managed data path escaped the application Data directory.");

            var relativePath = NormalizeRelativePath(Path.GetRelativePath(_dataDirectory, fullPath));
            if (!IsSafeRelativePath(relativePath))
                throw new InvalidOperationException("Managed data contains an unsafe relative path.");

            yield return new ManagedBackupSource(fullPath, relativePath, DetermineKind(relativePath));
        }
    }

    private static bool IsTransientDataFile(string path)
    {
        var name = Path.GetFileName(path);
        return name.StartsWith('~') ||
               name.EndsWith(".tmp", StringComparison.OrdinalIgnoreCase) ||
               name.EndsWith(".partial", StringComparison.OrdinalIgnoreCase) ||
               name.EndsWith(".lock", StringComparison.OrdinalIgnoreCase) ||
               string.Equals(name, ".DS_Store", StringComparison.OrdinalIgnoreCase);
    }

    private static BackupContentKind DetermineKind(string relativePath)
    {
        if (relativePath.EndsWith(".db", StringComparison.OrdinalIgnoreCase)) return BackupContentKind.Database;
        if (relativePath.EndsWith(".db-wal", StringComparison.OrdinalIgnoreCase) || relativePath.EndsWith(".db-shm", StringComparison.OrdinalIgnoreCase)) return BackupContentKind.DatabaseSidecar;
        if (relativePath.EndsWith(".json", StringComparison.OrdinalIgnoreCase) || relativePath.EndsWith(".jsonl", StringComparison.OrdinalIgnoreCase)) return BackupContentKind.ManagedState;
        return BackupContentKind.ManagedFile;
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
        var entry = new BackupAuditEntry(DateTime.UtcNow, action, fileName, succeeded, SanitizeAuditMessage(message));
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

    private static bool TryNormalizePayloadPath(string fullName, out string relativePath)
    {
        relativePath = string.Empty;
        if (!fullName.StartsWith(PayloadPrefix, StringComparison.OrdinalIgnoreCase)) return false;
        return TryNormalizeRelativePath(fullName[PayloadPrefix.Length..], out relativePath);
    }

    private static bool TryGetDeclaredRelativePath(int manifestVersion, BackupManifestFile file, out string relativePath)
    {
        var candidate = manifestVersion >= CurrentManifestVersion && !string.IsNullOrWhiteSpace(file.RelativePath)
            ? file.RelativePath
            : file.Name;
        return TryNormalizeRelativePath(candidate, out relativePath);
    }

    private static bool TryNormalizeRelativePath(string? value, out string relativePath)
    {
        relativePath = NormalizeRelativePath(value ?? string.Empty);
        return IsSafeRelativePath(relativePath);
    }

    private static string NormalizeRelativePath(string value) => value.Replace('\\', '/');

    private static bool IsSafeRelativePath(string value)
    {
        if (string.IsNullOrWhiteSpace(value) || value.StartsWith('/') || value.EndsWith('/') || value.Contains(':') || value.Contains('\0') || Path.IsPathRooted(value))
            return false;

        var segments = value.Split('/');
        return segments.Length > 0 && segments.All(x => !string.IsNullOrWhiteSpace(x) && x is not "." and not "..");
    }

    private static string ComputeSha256(string path)
    {
        using var stream = File.Open(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete);
        return Convert.ToHexString(SHA256.HashData(stream));
    }

    private static string ComputeSha256(ZipArchiveEntry entry)
    {
        using var stream = entry.Open();
        return Convert.ToHexString(SHA256.HashData(stream));
    }

    private static string? NormalizeNote(string? note)
    {
        if (string.IsNullOrWhiteSpace(note)) return null;
        var clean = string.Join(' ', note.Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries));
        return clean.Length <= 500 ? clean : clean[..500];
    }

    private static string SanitizeAuditMessage(string? message)
    {
        var clean = string.Join(' ', (message ?? string.Empty).Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries));
        return clean.Length <= 1000 ? clean : clean[..1000];
    }

    private static string FormatBytes(long value) => value switch
    {
        >= 1073741824 => $"{value / 1073741824d:0.00} GB",
        >= 1048576 => $"{value / 1048576d:0.00} MB",
        >= 1024 => $"{value / 1024d:0.0} KB",
        _ => $"{value} B"
    };

    private static readonly JsonSerializerOptions JsonOptions = new() { PropertyNameCaseInsensitive = true, WriteIndented = true };
    private sealed record ManagedBackupSource(string SourcePath, string RelativePath, BackupContentKind Kind);
}

public enum BackupContentKind
{
    Database = 0,
    DatabaseSidecar = 1,
    ManagedState = 2,
    ManagedFile = 3
}

public sealed record BackupFileInfo(string FileName, long SizeBytes, DateTime CreatedAtUtc, DateTime ModifiedAtUtc, bool IsValid, int DatabaseCount, string Sha256, string? Note);
public sealed record BackupVerificationResult(bool IsValid, string Message, int DatabaseCount, DateTime? CreatedAtUtc);
public sealed record BackupManifest(int Version, DateTime CreatedAtUtc, string? Note, string MachineName, IReadOnlyList<BackupManifestFile> Files);
public sealed record BackupManifestFile(string Name, long SizeBytes, string? Sha256 = null, string? RelativePath = null, string Kind = "Database");
public sealed record BackupAuditEntry(DateTime TimestampUtc, string Action, string FileName, bool Succeeded, string Message);
public sealed record RestoreReadinessCheck(string Name, string NameAr, bool Passed, string Message);
public sealed record RestoreReadinessResult(string FileName, bool IsReady, string Message, IReadOnlyList<RestoreReadinessCheck> Checks, BackupInspectionResult Inspection);
public sealed record BackupInspectionResult(string FileName, bool IsValid, string Message, int ManifestVersion, DateTime? CreatedAtUtc, string? Note, string? MachineName, int DatabaseCount, long UncompressedBytes, IReadOnlyList<BackupManifestFile> Files)
{
    public static BackupInspectionResult Invalid(string fileName, string message, int manifestVersion = 0, DateTime? createdAtUtc = null) =>
        new(fileName, false, message, manifestVersion, createdAtUtc, null, null, 0, 0, Array.Empty<BackupManifestFile>());
}
