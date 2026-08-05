using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;

namespace AIWordPressManager.Web.Services;

public sealed class BackupManagementService
{
    private readonly object _sync = new();
    private readonly string _dataDirectory;
    private readonly string _backupDirectory;

    public BackupManagementService()
    {
        _dataDirectory = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AIWordPressManager", "Data");
        _backupDirectory = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AIWordPressManager", "Backups");
        Directory.CreateDirectory(_dataDirectory);
        Directory.CreateDirectory(_backupDirectory);
    }

    public string BackupDirectory => _backupDirectory;

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
                1,
                now,
                string.IsNullOrWhiteSpace(note) ? null : note.Trim(),
                Environment.MachineName,
                sources.Select(x => new BackupManifestFile(Path.GetFileName(x), new FileInfo(x).Length)).ToList());

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
                writer.Write(JsonSerializer.Serialize(manifest, new JsonSerializerOptions { WriteIndented = true }));
            }

            return CreateInfo(target);
        }
    }

    public BackupVerificationResult Verify(string fileName)
    {
        lock (_sync)
        {
            var path = ResolvePath(fileName);
            if (!File.Exists(path)) return new(false, "Backup file was not found.", 0, null);
            try
            {
                using var archive = ZipFile.OpenRead(path);
                var manifestEntry = archive.GetEntry("manifest.json");
                if (manifestEntry is null) return new(false, "The backup manifest is missing.", 0, null);
                using var reader = new StreamReader(manifestEntry.Open());
                var manifest = JsonSerializer.Deserialize<BackupManifest>(reader.ReadToEnd());
                if (manifest is null || manifest.Version != 1) return new(false, "The backup manifest is invalid.", 0, null);
                var databases = archive.Entries.Count(x => x.FullName.StartsWith("data/", StringComparison.OrdinalIgnoreCase) && x.Name.EndsWith(".db", StringComparison.OrdinalIgnoreCase));
                if (databases == 0) return new(false, "The archive does not contain database files.", 0, manifest.CreatedAtUtc);
                return new(true, "Backup verification completed successfully.", databases, manifest.CreatedAtUtc);
            }
            catch (InvalidDataException ex)
            {
                return new(false, ex.Message, 0, null);
            }
        }
    }

    public void Delete(string fileName)
    {
        lock (_sync)
        {
            var path = ResolvePath(fileName);
            if (File.Exists(path)) File.Delete(path);
        }
    }

    public string GetDownloadPath(string fileName)
    {
        var path = ResolvePath(fileName);
        if (!File.Exists(path)) throw new FileNotFoundException("Backup file was not found.", fileName);
        return path;
    }

    private BackupFileInfo CreateInfo(string path)
    {
        var file = new FileInfo(path);
        var verification = Verify(file.Name);
        return new(file.Name, file.Length, file.CreationTimeUtc, file.LastWriteTimeUtc, verification.IsValid, verification.DatabaseCount, ComputeSha256(path));
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
}

public sealed record BackupFileInfo(string FileName, long SizeBytes, DateTime CreatedAtUtc, DateTime ModifiedAtUtc, bool IsValid, int DatabaseCount, string Sha256);
public sealed record BackupVerificationResult(bool IsValid, string Message, int DatabaseCount, DateTime? CreatedAtUtc);
public sealed record BackupManifest(int Version, DateTime CreatedAtUtc, string? Note, string MachineName, IReadOnlyList<BackupManifestFile> Files);
public sealed record BackupManifestFile(string Name, long SizeBytes);
