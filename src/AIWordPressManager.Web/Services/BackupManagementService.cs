using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions;
using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Web.Services;

public sealed class BackupManagementService
{
    private const int CurrentManifestVersion = 5;
    private const int CryptographicManifestVersion = 3;
    private const int ScopedPayloadManifestVersion = 4;
    private const int SecretRecoveryManifestVersion = 5;
    private const string LegacyPayloadPrefix = "data/";
    private const string ScopedPayloadPrefix = "payload/";
    private const string SecretRecoveryBlocked = "wrapped-key-required";
    private readonly object _sync = new();
    private readonly string _applicationRoot;
    private readonly string _dataDirectory;
    private readonly string _configurationDirectory;
    private readonly string _backupDirectory;
    private readonly string _historyPath;

    public BackupManagementService(string? rootDirectory = null, string? environmentName = null)
    {
        _applicationRoot = string.IsNullOrWhiteSpace(rootDirectory)
            ? GetDefaultApplicationRoot(environmentName)
            : Path.GetFullPath(rootDirectory);
        _dataDirectory = Path.Combine(_applicationRoot, "Data");
        _configurationDirectory = Path.Combine(_applicationRoot, "Config");
        _backupDirectory = Path.Combine(_applicationRoot, "Backups");
        _historyPath = Path.Combine(_backupDirectory, "backup-history.jsonl");
        Directory.CreateDirectory(_dataDirectory);
        Directory.CreateDirectory(_configurationDirectory);
        Directory.CreateDirectory(_backupDirectory);
    }

    public string ApplicationRoot => _applicationRoot;
    public string BackupDirectory => _backupDirectory;
    public string DataDirectory => _dataDirectory;
    public string ConfigurationDirectory => _configurationDirectory;

    public static string GetDefaultApplicationRoot(string? environmentName = null, string? localApplicationData = null)
    {
        var localRoot = string.IsNullOrWhiteSpace(localApplicationData)
            ? Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData)
            : Path.GetFullPath(localApplicationData);
        if (string.IsNullOrWhiteSpace(localRoot)) localRoot = AppContext.BaseDirectory;

        var applicationRoot = Path.Combine(localRoot, "AIWordPressManager");
        var effectiveEnvironment = string.IsNullOrWhiteSpace(environmentName)
            ? Environment.GetEnvironmentVariable("ASPNETCORE_ENVIRONMENT") ?? Environment.GetEnvironmentVariable("DOTNET_ENVIRONMENT")
            : environmentName;
        return string.Equals(effectiveEnvironment, "Development", StringComparison.OrdinalIgnoreCase)
            ? Path.Combine(applicationRoot, "Development")
            : applicationRoot;
    }

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

    public BackupFileInfo CreateBackup(string? note = null, string? wrappedSecretKeyEnvelope = null)
    {
        lock (_sync)
        {
            var normalizedWrappedEnvelope = NormalizeWrappedEnvelope(wrappedSecretKeyEnvelope);
            var secretRecoveryMode = normalizedWrappedEnvelope is null
                ? SecretRecoveryBlocked
                : SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1Mode;
            var now = DateTime.UtcNow;
            var fileName = $"AIWM-Backup-{now:yyyyMMdd-HHmmss}-{Guid.NewGuid():N}.zip";
            var target = Path.Combine(_backupDirectory, fileName);
            var databaseProvider = ReadConfiguredDatabaseProvider();
            if (!string.IsNullOrWhiteSpace(databaseProvider) &&
                !databaseProvider.Equals("SQLite", StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidOperationException(
                    $"Database provider '{databaseProvider}' requires a provider-native backup path. Backup creation is blocked rather than producing an incomplete archive.");
            }

            var sources = EnumerateManagedFiles().ToList();
            if (!sources.Any(x => x.Kind == BackupContentKind.Database))
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
                manifestFiles,
                databaseProvider,
                secretRecoveryMode,
                normalizedWrappedEnvelope);

            try
            {
                using (var archive = ZipFile.Open(target, ZipArchiveMode.Create))
                {
                    foreach (var source in sources)
                    {
                        archive.CreateEntryFromFile(
                            source.SourcePath,
                            ScopedPayloadPrefix + source.RelativePath,
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
                    $"Created verified backup containing {manifestFiles.Count} managed file(s), including {info.DatabaseCount} database file(s) and {manifestFiles.Count(x => x.Kind == nameof(BackupContentKind.Configuration))} configuration file(s). Secret recovery material: {(normalizedWrappedEnvelope is null ? "not included" : "wrapped envelope included")}.");
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

    public RestoreReadinessResult CheckRestoreReadiness(string fileName, bool secretRecoveryValidated = false)
    {
        lock (_sync)
        {
            var inspection = InspectInternal(fileName);
            var hasDatabaseConfiguration = inspection.Files.Any(x =>
                string.Equals(x.Kind, nameof(BackupContentKind.Configuration), StringComparison.OrdinalIgnoreCase) &&
                string.Equals(x.RelativePath, "Config/setup.database.json", StringComparison.OrdinalIgnoreCase));
            var hasWrappedRecoveryMaterial = string.Equals(
                                                inspection.SecretRecoveryMode,
                                                SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1Mode,
                                                StringComparison.OrdinalIgnoreCase) &&
                                            IsStructurallyValidWrappedEnvelope(inspection.WrappedSecretKeyEnvelope);
            var secretRecoveryAvailable = hasWrappedRecoveryMaterial && secretRecoveryValidated;
            var secretRecoveryMessage = secretRecoveryAvailable
                ? "The wrapped secret-protection key is present and the recovery secret was cryptographically validated for this preflight."
                : hasWrappedRecoveryMaterial
                    ? "Wrapped secret recovery material is present, but the recovery secret has not been cryptographically validated for this preflight."
                    : "The live AES secret key is intentionally not stored in this unencrypted ZIP. Disaster recovery of protected secrets requires a wrapped-key envelope created with a separate recovery secret.";
            var checks = new List<RestoreReadinessCheck>
            {
                new("Archive integrity", "سلامة ملف النسخة", inspection.IsValid, inspection.Message),
                new("Database content", "محتوى قواعد البيانات", inspection.DatabaseCount > 0, inspection.DatabaseCount > 0 ? $"{inspection.DatabaseCount} database file(s) found." : "No database files found."),
                new("Configuration state", "حالة الإعدادات", hasDatabaseConfiguration, hasDatabaseConfiguration ? "Database setup configuration is included." : "The environment database setup configuration is not included in this archive."),
                new("Managed data coverage", "تغطية بيانات التطبيق", inspection.Files.Count >= inspection.DatabaseCount && inspection.Files.Count > 0, $"{inspection.Files.Count} managed file(s) declared in the manifest."),
                new("Protected secret recovery", "استعادة الأسرار المحمية", secretRecoveryAvailable, secretRecoveryMessage),
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

            var payloadPrefix = manifest.Version >= ScopedPayloadManifestVersion ? ScopedPayloadPrefix : LegacyPayloadPrefix;
            var issues = new List<string>();
            if (manifest.Version >= SecretRecoveryManifestVersion)
                ValidateSecretRecoveryManifest(manifest, issues);

            foreach (var entry in archive.Entries.Where(x => !string.IsNullOrEmpty(x.Name)))
            {
                if (string.Equals(entry.FullName, "manifest.json", StringComparison.OrdinalIgnoreCase)) continue;
                if (!entry.FullName.StartsWith(payloadPrefix, StringComparison.OrdinalIgnoreCase))
                    issues.Add($"Unexpected archive entry: {entry.FullName}");
            }

            var payloadEntries = archive.Entries
                .Where(x => !string.IsNullOrEmpty(x.Name) && x.FullName.StartsWith(payloadPrefix, StringComparison.OrdinalIgnoreCase))
                .ToList();
            if (payloadEntries.Count == 0)
                return BackupInspectionResult.Invalid(fileName, "The archive does not contain managed application files.", manifest.Version, manifest.CreatedAtUtc);

            var payloadByPath = new Dictionary<string, ZipArchiveEntry>(StringComparer.OrdinalIgnoreCase);
            foreach (var entry in payloadEntries)
            {
                if (!TryNormalizePayloadPath(entry.FullName, payloadPrefix, out var relativePath))
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

                if (manifest.Version >= CryptographicManifestVersion)
                {
                    if (!TryDetermineExpectedKind(manifest.Version, relativePath, out var expectedKind) ||
                        !Enum.TryParse<BackupContentKind>(declared.Kind, true, out var declaredKind) ||
                        declaredKind != expectedKind)
                    {
                        issues.Add($"Content kind mismatch: {relativePath}");
                    }

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

            var databaseCount = manifest.Files.Count(declared =>
            {
                if (!TryGetDeclaredRelativePath(manifest.Version, declared, out var relativePath) ||
                    !payloadByPath.ContainsKey(relativePath) ||
                    !TryDetermineExpectedKind(manifest.Version, relativePath, out var expectedKind) ||
                    expectedKind != BackupContentKind.Database)
                {
                    return false;
                }

                return manifest.Version < CryptographicManifestVersion ||
                       (Enum.TryParse<BackupContentKind>(declared.Kind, true, out var declaredKind) &&
                        declaredKind == BackupContentKind.Database);
            });
            if (databaseCount == 0) issues.Add("The archive does not contain database files.");

            var valid = issues.Count == 0;
            var verificationMode = manifest.Version >= CryptographicManifestVersion
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
                manifest.Files,
                manifest.DatabaseProvider,
                manifest.SecretRecoveryMode,
                manifest.WrappedSecretKeyEnvelope);
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

    private IEnumerable<ManagedBackupSource> EnumerateManagedFiles()
    {
        foreach (var source in EnumerateScope(_dataDirectory, "Data", configurationScope: false)) yield return source;
        foreach (var source in EnumerateScope(_configurationDirectory, "Config", configurationScope: true)) yield return source;
    }

    private static IEnumerable<ManagedBackupSource> EnumerateScope(string directory, string scope, bool configurationScope)
    {
        if (!Directory.Exists(directory)) yield break;
        var scopeRoot = Path.GetFullPath(directory) + Path.DirectorySeparatorChar;
        foreach (var path in Directory.EnumerateFiles(directory, "*", SearchOption.AllDirectories))
        {
            if (IsTransientFile(path)) continue;

            var fullPath = Path.GetFullPath(path);
            if (!fullPath.StartsWith(scopeRoot, StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException($"Managed {scope} path escaped its application directory.");

            var childPath = NormalizeRelativePath(Path.GetRelativePath(directory, fullPath));
            if (!IsSafeRelativePath(childPath))
                throw new InvalidOperationException($"Managed {scope} data contains an unsafe relative path.");

            var relativePath = $"{scope}/{childPath}";
            yield return new ManagedBackupSource(
                fullPath,
                relativePath,
                configurationScope ? BackupContentKind.Configuration : DetermineDataKind(childPath));
        }
    }

    private static bool IsTransientFile(string path)
    {
        var name = Path.GetFileName(path);
        return name.StartsWith('~') ||
               name.EndsWith(".tmp", StringComparison.OrdinalIgnoreCase) ||
               name.EndsWith(".partial", StringComparison.OrdinalIgnoreCase) ||
               name.EndsWith(".lock", StringComparison.OrdinalIgnoreCase) ||
               string.Equals(name, ".DS_Store", StringComparison.OrdinalIgnoreCase);
    }

    private static bool TryDetermineExpectedKind(int manifestVersion, string relativePath, out BackupContentKind kind)
    {
        if (manifestVersion >= ScopedPayloadManifestVersion)
        {
            if (relativePath.StartsWith("Config/", StringComparison.OrdinalIgnoreCase))
            {
                kind = BackupContentKind.Configuration;
                return true;
            }

            if (relativePath.StartsWith("Data/", StringComparison.OrdinalIgnoreCase))
            {
                kind = DetermineDataKind(relativePath["Data/".Length..]);
                return true;
            }

            kind = default;
            return false;
        }

        kind = DetermineDataKind(relativePath);
        return true;
    }

    private static BackupContentKind DetermineDataKind(string relativePath)
    {
        if (relativePath.EndsWith(".db", StringComparison.OrdinalIgnoreCase)) return BackupContentKind.Database;
        if (relativePath.EndsWith(".db-wal", StringComparison.OrdinalIgnoreCase) || relativePath.EndsWith(".db-shm", StringComparison.OrdinalIgnoreCase)) return BackupContentKind.DatabaseSidecar;
        if (relativePath.EndsWith(".json", StringComparison.OrdinalIgnoreCase) || relativePath.EndsWith(".jsonl", StringComparison.OrdinalIgnoreCase)) return BackupContentKind.ManagedState;
        return BackupContentKind.ManagedFile;
    }

    private string? ReadConfiguredDatabaseProvider()
    {
        var path = Path.Combine(_configurationDirectory, "setup.database.json");
        if (!File.Exists(path)) return null;

        try
        {
            using var document = JsonDocument.Parse(File.ReadAllText(path));
            if (!document.RootElement.TryGetProperty("Database", out var database) || database.ValueKind != JsonValueKind.Object)
                throw new InvalidDataException("Database setup configuration is invalid. Backup creation is blocked until the configuration is repaired.");
            if (!database.TryGetProperty("Provider", out var provider) || provider.ValueKind != JsonValueKind.String || string.IsNullOrWhiteSpace(provider.GetString()))
                throw new InvalidDataException("Database setup configuration does not contain a valid provider. Backup creation is blocked until the configuration is repaired.");

            var providerName = provider.GetString()!.Trim();
            if (!providerName.Equals("SQLite", StringComparison.OrdinalIgnoreCase))
                return providerName;

            if (!database.TryGetProperty("ConnectionString", out var connectionStringElement) ||
                connectionStringElement.ValueKind != JsonValueKind.String ||
                string.IsNullOrWhiteSpace(connectionStringElement.GetString()))
            {
                throw new InvalidDataException("SQLite database setup configuration does not contain a connection string. Backup creation is blocked until the configuration is repaired.");
            }

            string configuredDatabasePath;
            try
            {
                var builder = new SqliteConnectionStringBuilder(connectionStringElement.GetString());
                if (string.IsNullOrWhiteSpace(builder.DataSource))
                    throw new InvalidDataException("SQLite database setup configuration does not contain a database file path.");
                configuredDatabasePath = Path.GetFullPath(builder.DataSource);
            }
            catch (ArgumentException ex)
            {
                throw new InvalidDataException("SQLite database setup connection string is invalid. Backup creation is blocked until the configuration is repaired.", ex);
            }

            var dataRoot = Path.GetFullPath(_dataDirectory) + Path.DirectorySeparatorChar;
            if (!configuredDatabasePath.StartsWith(dataRoot, StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidOperationException(
                    "The configured SQLite database is outside the managed Data directory. Backup creation is blocked until an external-path SQLite snapshot strategy is available.");
            }

            if (!configuredDatabasePath.EndsWith(".db", StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidOperationException(
                    "The configured SQLite database uses an unsupported file extension for the current backup strategy. Backup creation is blocked until the snapshot strategy supports it explicitly.");
            }

            if (!File.Exists(configuredDatabasePath))
                throw new InvalidOperationException("The configured SQLite database file was not found. Backup creation is blocked rather than archiving a different local database.");

            return providerName;
        }
        catch (JsonException ex)
        {
            throw new InvalidDataException("Database setup configuration is invalid JSON. Backup creation is blocked until the configuration is repaired.", ex);
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
            var root = Path.GetPathRoot(_applicationRoot);
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

    private static void ValidateSecretRecoveryManifest(BackupManifest manifest, ICollection<string> issues)
    {
        if (string.Equals(manifest.SecretRecoveryMode, SecretRecoveryBlocked, StringComparison.OrdinalIgnoreCase))
        {
            if (!string.IsNullOrWhiteSpace(manifest.WrappedSecretKeyEnvelope))
                issues.Add("A blocked secret-recovery manifest cannot contain a wrapped key envelope.");
            return;
        }

        if (string.Equals(manifest.SecretRecoveryMode, SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1Mode, StringComparison.OrdinalIgnoreCase))
        {
            if (!IsStructurallyValidWrappedEnvelope(manifest.WrappedSecretKeyEnvelope))
                issues.Add("The wrapped secret-recovery envelope is missing or malformed.");
            return;
        }

        issues.Add("The backup manifest contains an unsupported secret-recovery mode.");
    }

    private static string? NormalizeWrappedEnvelope(string? wrappedSecretKeyEnvelope)
    {
        if (string.IsNullOrWhiteSpace(wrappedSecretKeyEnvelope)) return null;
        var candidate = wrappedSecretKeyEnvelope.Trim();
        if (!IsStructurallyValidWrappedEnvelope(candidate))
            throw new ArgumentException("The wrapped secret-recovery envelope is malformed or unsupported.", nameof(wrappedSecretKeyEnvelope));
        return candidate;
    }

    private static bool IsStructurallyValidWrappedEnvelope(string? wrappedSecretKeyEnvelope)
    {
        if (string.IsNullOrWhiteSpace(wrappedSecretKeyEnvelope) ||
            wrappedSecretKeyEnvelope.Length > SecretRecoveryKeyEnvelopeFormat.MaximumEnvelopeLength ||
            !wrappedSecretKeyEnvelope.StartsWith(SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1Prefix, StringComparison.Ordinal))
        {
            return false;
        }

        try
        {
            var payload = Convert.FromBase64String(wrappedSecretKeyEnvelope[SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1Prefix.Length..]);
            try
            {
                return payload.Length == SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1PayloadBytes;
            }
            finally
            {
                CryptographicOperations.ZeroMemory(payload);
            }
        }
        catch (FormatException)
        {
            return false;
        }
    }

    private static bool TryNormalizePayloadPath(string fullName, string payloadPrefix, out string relativePath)
    {
        relativePath = string.Empty;
        if (!fullName.StartsWith(payloadPrefix, StringComparison.OrdinalIgnoreCase)) return false;
        return TryNormalizeRelativePath(fullName[payloadPrefix.Length..], out relativePath);
    }

    private static bool TryGetDeclaredRelativePath(int manifestVersion, BackupManifestFile file, out string relativePath)
    {
        var candidate = manifestVersion >= CryptographicManifestVersion && !string.IsNullOrWhiteSpace(file.RelativePath)
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
    ManagedFile = 3,
    Configuration = 4
}

public sealed record BackupFileInfo(string FileName, long SizeBytes, DateTime CreatedAtUtc, DateTime ModifiedAtUtc, bool IsValid, int DatabaseCount, string Sha256, string? Note);
public sealed record BackupVerificationResult(bool IsValid, string Message, int DatabaseCount, DateTime? CreatedAtUtc);
public sealed record BackupManifest(
    int Version,
    DateTime CreatedAtUtc,
    string? Note,
    string MachineName,
    IReadOnlyList<BackupManifestFile> Files,
    string? DatabaseProvider = null,
    string? SecretRecoveryMode = null,
    string? WrappedSecretKeyEnvelope = null);
public sealed record BackupManifestFile(string Name, long SizeBytes, string? Sha256 = null, string? RelativePath = null, string Kind = "Database");
public sealed record BackupAuditEntry(DateTime TimestampUtc, string Action, string FileName, bool Succeeded, string Message);
public sealed record RestoreReadinessCheck(string Name, string NameAr, bool Passed, string Message);
public sealed record RestoreReadinessResult(string FileName, bool IsReady, string Message, IReadOnlyList<RestoreReadinessCheck> Checks, BackupInspectionResult Inspection);
public sealed record BackupInspectionResult(
    string FileName,
    bool IsValid,
    string Message,
    int ManifestVersion,
    DateTime? CreatedAtUtc,
    string? Note,
    string? MachineName,
    int DatabaseCount,
    long UncompressedBytes,
    IReadOnlyList<BackupManifestFile> Files,
    string? DatabaseProvider = null,
    string? SecretRecoveryMode = null,
    string? WrappedSecretKeyEnvelope = null)
{
    public static BackupInspectionResult Invalid(string fileName, string message, int manifestVersion = 0, DateTime? createdAtUtc = null) =>
        new(fileName, false, message, manifestVersion, createdAtUtc, null, null, 0, 0, Array.Empty<BackupManifestFile>());
}
