using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Nodes;
using AIWordPressManager.Application.Abstractions;
using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Infrastructure.Security;

public sealed class OfflineApplicationRestoreService
{
    private const int SupportedManifestVersion = 5;
    private const long MaximumManifestBytes = 64 * 1024;
    private const long MaximumRestoreBytes = 50L * 1024 * 1024 * 1024;
    private const int MaximumFileCount = 100_000;
    private const string PayloadPrefix = "payload/";

    public OfflineApplicationRestoreResult RestoreFromBackup(
        string backupPath,
        string recoverySecret,
        bool replaceExistingKey = false,
        string? applicationRoot = null,
        string? localApplicationData = null)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(backupPath);
        ArgumentException.ThrowIfNullOrWhiteSpace(recoverySecret);

        var fullBackupPath = Path.GetFullPath(backupPath);
        if (!File.Exists(fullBackupPath))
            throw new FileNotFoundException("The recovery backup archive was not found.", fullBackupPath);

        var resolvedLocalApplicationData = ResolveLocalApplicationData(applicationRoot, localApplicationData);
        var resolvedApplicationRoot = ResolveApplicationRoot(applicationRoot, resolvedLocalApplicationData);
        Directory.CreateDirectory(resolvedApplicationRoot);

        using var recoveryLease = SecretProtectionStorage.AcquireRecoveryExclusiveLease(resolvedLocalApplicationData);
        var plan = BuildRestorePlan(fullBackupPath);

        byte[]? validatedRecoveryKey = null;
        try
        {
            validatedRecoveryKey = SecretRecoveryEnvelopeCodec.Unwrap(plan.Manifest.WrappedSecretKeyEnvelope!, recoverySecret);
        }
        finally
        {
            if (validatedRecoveryKey is not null) CryptographicOperations.ZeroMemory(validatedRecoveryKey);
        }

        var operationId = Guid.NewGuid().ToString("N");
        var stagingRoot = Path.Combine(resolvedApplicationRoot, $".restore-staging-{operationId}");
        var rollbackRoot = Path.Combine(resolvedApplicationRoot, $".restore-rollback-{operationId}");
        var targetData = Path.Combine(resolvedApplicationRoot, "Data");
        var targetConfig = Path.Combine(resolvedApplicationRoot, "Config");
        var rollbackData = Path.Combine(rollbackRoot, "Data");
        var rollbackConfig = Path.Combine(rollbackRoot, "Config");
        var keyPath = SecretProtectionStorage.GetKeyPath(resolvedLocalApplicationData);
        var rollbackKeyPath = Path.Combine(rollbackRoot, "Security", SecretProtectionStorage.KeyFileName);

        var originalDataMoved = false;
        var originalConfigMoved = false;
        var restoredDataInstalled = false;
        var restoredConfigInstalled = false;
        var keyMutationStarted = false;
        var hadOriginalKey = File.Exists(keyPath);

        try
        {
            ExtractAndVerify(fullBackupPath, plan, stagingRoot);
            var stagedDatabase = GetSingleDatabasePath(plan, stagingRoot);
            ValidateSqliteDatabase(stagedDatabase);

            var stagedConfig = Path.Combine(stagingRoot, "Config", "setup.database.json");
            if (!File.Exists(stagedConfig))
                throw new InvalidDataException("The restore archive does not contain Config/setup.database.json.");

            Directory.CreateDirectory(rollbackRoot);
            if (hadOriginalKey)
            {
                Directory.CreateDirectory(Path.GetDirectoryName(rollbackKeyPath)!);
                File.Copy(keyPath, rollbackKeyPath, overwrite: false);
            }

            if (Directory.Exists(targetData))
            {
                Directory.Move(targetData, rollbackData);
                originalDataMoved = true;
            }

            if (Directory.Exists(targetConfig))
            {
                Directory.Move(targetConfig, rollbackConfig);
                originalConfigMoved = true;
            }

            var stagedDataDirectory = Path.Combine(stagingRoot, "Data");
            var stagedConfigDirectory = Path.Combine(stagingRoot, "Config");
            if (!Directory.Exists(stagedDataDirectory) || !Directory.Exists(stagedConfigDirectory))
                throw new InvalidDataException("The restore archive must contain both Data and Config scopes.");

            Directory.Move(stagedDataDirectory, targetData);
            restoredDataInstalled = true;
            Directory.Move(stagedConfigDirectory, targetConfig);
            restoredConfigInstalled = true;

            var restoredDatabase = GetSingleDatabasePath(plan, resolvedApplicationRoot);
            var restoredSetupConfiguration = Path.Combine(targetConfig, "setup.database.json");
            NormalizeSqliteConfiguration(restoredSetupConfiguration, restoredDatabase);
            ValidateSqliteConfiguration(restoredSetupConfiguration, restoredDatabase);
            ValidateSqliteDatabase(restoredDatabase);

            keyMutationStarted = true;
            var keyResult = new OfflineSecretRecoveryInstaller().InstallFromBackup(
                fullBackupPath,
                recoverySecret,
                replaceExistingKey,
                resolvedLocalApplicationData,
                recoveryLease);

            ValidateSqliteDatabase(restoredDatabase);
            WriteRestoreProvenance(
                resolvedApplicationRoot,
                fullBackupPath,
                plan,
                restoredDatabase,
                keyResult.Status);

            DeleteDirectoryBestEffort(rollbackRoot);
            DeleteDirectoryBestEffort(stagingRoot);

            return new OfflineApplicationRestoreResult(
                Path.GetFileName(fullBackupPath),
                resolvedApplicationRoot,
                restoredDatabase,
                plan.Manifest.Files.Count,
                plan.Manifest.CreatedAtUtc,
                keyResult.Status,
                "Application data, configuration, SQLite database, and protected-secret key were restored and verified successfully.");
        }
        catch
        {
            try
            {
                RollBack(
                    targetData,
                    targetConfig,
                    rollbackData,
                    rollbackConfig,
                    originalDataMoved,
                    originalConfigMoved,
                    restoredDataInstalled,
                    restoredConfigInstalled,
                    keyPath,
                    rollbackKeyPath,
                    hadOriginalKey,
                    keyMutationStarted);
            }
            finally
            {
                DeleteDirectoryBestEffort(stagingRoot);
                DeleteDirectoryBestEffort(rollbackRoot);
            }
            throw;
        }
    }

    private static RestorePlan BuildRestorePlan(string backupPath)
    {
        using var archive = ZipFile.OpenRead(backupPath);
        var manifestEntries = archive.Entries
            .Where(x => string.Equals(x.FullName, "manifest.json", StringComparison.OrdinalIgnoreCase))
            .ToList();
        if (manifestEntries.Count != 1)
            throw new InvalidDataException(manifestEntries.Count == 0 ? "The backup manifest is missing." : "The archive contains duplicate backup manifests.");

        var manifestEntry = manifestEntries[0];
        if (manifestEntry.Length <= 0 || manifestEntry.Length > MaximumManifestBytes)
            throw new InvalidDataException("The backup manifest size is invalid or exceeds the safety limit.");

        RestoreManifest? manifest;
        using (var stream = manifestEntry.Open())
        {
            manifest = JsonSerializer.Deserialize<RestoreManifest>(stream, JsonOptions);
        }

        if (manifest is null || manifest.Version != SupportedManifestVersion)
            throw new InvalidDataException($"Full offline restore requires backup manifest version {SupportedManifestVersion}.");
        if (manifest.Files is null || manifest.Files.Count == 0 || manifest.Files.Count > MaximumFileCount)
            throw new InvalidDataException("The backup manifest contains an invalid managed-file count.");
        if (!string.IsNullOrWhiteSpace(manifest.DatabaseProvider) &&
            !string.Equals(manifest.DatabaseProvider, "SQLite", StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidOperationException($"Database provider '{manifest.DatabaseProvider}' requires provider-native restore and cannot be restored by the SQLite offline recovery path.");
        }
        if (!string.Equals(manifest.SecretRecoveryMode, SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1Mode, StringComparison.Ordinal) ||
            string.IsNullOrWhiteSpace(manifest.WrappedSecretKeyEnvelope))
        {
            throw new InvalidDataException("Full offline restore requires authenticated wrapped secret-recovery material in the backup.");
        }

        var payloadEntries = archive.Entries
            .Where(x => !string.IsNullOrEmpty(x.Name) && x.FullName.StartsWith(PayloadPrefix, StringComparison.OrdinalIgnoreCase))
            .ToList();
        var unexpected = archive.Entries
            .Where(x => !string.IsNullOrEmpty(x.Name) &&
                        !string.Equals(x.FullName, "manifest.json", StringComparison.OrdinalIgnoreCase) &&
                        !x.FullName.StartsWith(PayloadPrefix, StringComparison.OrdinalIgnoreCase))
            .Select(x => x.FullName)
            .FirstOrDefault();
        if (unexpected is not null)
            throw new InvalidDataException($"Unexpected archive entry: {unexpected}");

        var entriesByRelativePath = new Dictionary<string, ZipArchiveEntry>(StringComparer.OrdinalIgnoreCase);
        foreach (var entry in payloadEntries)
        {
            var relativePath = NormalizeRelativePath(entry.FullName[PayloadPrefix.Length..]);
            ValidateManagedRelativePath(relativePath);
            if (!entriesByRelativePath.TryAdd(relativePath, entry))
                throw new InvalidDataException($"Duplicate archive entry: {relativePath}");
        }

        var declaredPaths = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        long totalBytes = 0;
        foreach (var file in manifest.Files)
        {
            var relativePath = NormalizeRelativePath(file.RelativePath ?? file.Name);
            ValidateManagedRelativePath(relativePath);
            if (!declaredPaths.Add(relativePath))
                throw new InvalidDataException($"Duplicate manifest entry: {relativePath}");
            if (!entriesByRelativePath.TryGetValue(relativePath, out var entry))
                throw new InvalidDataException($"Missing archive entry: {relativePath}");
            if (file.SizeBytes < 0 || entry.Length != file.SizeBytes)
                throw new InvalidDataException($"Size mismatch: {relativePath}");
            if (string.IsNullOrWhiteSpace(file.Sha256))
                throw new InvalidDataException($"Missing SHA-256: {relativePath}");

            totalBytes = checked(totalBytes + file.SizeBytes);
            if (totalBytes > MaximumRestoreBytes)
                throw new InvalidDataException("The declared restore payload exceeds the safety limit.");

            using var content = entry.Open();
            var hash = Convert.ToHexString(SHA256.HashData(content));
            if (!string.Equals(hash, file.Sha256.Trim(), StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException($"SHA-256 mismatch: {relativePath}");
        }

        foreach (var path in entriesByRelativePath.Keys)
        {
            if (!declaredPaths.Contains(path))
                throw new InvalidDataException($"Undeclared archive entry: {path}");
        }

        var primaryDatabases = manifest.Files
            .Where(x => string.Equals(x.Kind, "Database", StringComparison.OrdinalIgnoreCase))
            .ToList();
        if (primaryDatabases.Count != 1)
            throw new InvalidDataException("Full SQLite restore requires exactly one primary database file.");

        var setupConfig = manifest.Files.Any(x =>
            string.Equals(NormalizeRelativePath(x.RelativePath ?? x.Name), "Config/setup.database.json", StringComparison.OrdinalIgnoreCase));
        if (!setupConfig)
            throw new InvalidDataException("Full restore requires Config/setup.database.json.");

        return new RestorePlan(manifest);
    }

    private static void ExtractAndVerify(string backupPath, RestorePlan plan, string stagingRoot)
    {
        Directory.CreateDirectory(stagingRoot);
        using var archive = ZipFile.OpenRead(backupPath);
        var entries = archive.Entries
            .Where(x => !string.IsNullOrEmpty(x.Name) && x.FullName.StartsWith(PayloadPrefix, StringComparison.OrdinalIgnoreCase))
            .ToDictionary(
                x => NormalizeRelativePath(x.FullName[PayloadPrefix.Length..]),
                x => x,
                StringComparer.OrdinalIgnoreCase);

        foreach (var file in plan.Manifest.Files)
        {
            var relativePath = NormalizeRelativePath(file.RelativePath ?? file.Name);
            var entry = entries[relativePath];
            var target = GetSafeTargetPath(stagingRoot, relativePath);
            Directory.CreateDirectory(Path.GetDirectoryName(target)!);

            using (var input = entry.Open())
            using (var output = new FileStream(target, FileMode.CreateNew, FileAccess.Write, FileShare.None))
                input.CopyTo(output);

            var info = new FileInfo(target);
            if (info.Length != file.SizeBytes)
                throw new InvalidDataException($"Extracted size mismatch: {relativePath}");
            using var content = File.OpenRead(target);
            var hash = Convert.ToHexString(SHA256.HashData(content));
            if (!string.Equals(hash, file.Sha256, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException($"Extracted SHA-256 mismatch: {relativePath}");
        }
    }

    private static string GetSingleDatabasePath(RestorePlan plan, string applicationRoot)
    {
        var file = plan.Manifest.Files.Single(x => string.Equals(x.Kind, "Database", StringComparison.OrdinalIgnoreCase));
        return GetSafeTargetPath(applicationRoot, NormalizeRelativePath(file.RelativePath ?? file.Name));
    }

    private static void ValidateSqliteDatabase(string databasePath)
    {
        if (!File.Exists(databasePath))
            throw new FileNotFoundException("The restored SQLite database file is missing.", databasePath);

        var builder = new SqliteConnectionStringBuilder
        {
            DataSource = databasePath,
            Mode = SqliteOpenMode.ReadOnly
        };
        using var connection = new SqliteConnection(builder.ToString());
        connection.Open();
        using var command = connection.CreateCommand();
        command.CommandText = "PRAGMA quick_check;";
        var result = Convert.ToString(command.ExecuteScalar());
        if (!string.Equals(result, "ok", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException($"The restored SQLite database failed quick_check: {result ?? "no result"}.");
    }

    private static void NormalizeSqliteConfiguration(string configurationPath, string databasePath)
    {
        JsonNode? root;
        try
        {
            root = JsonNode.Parse(File.ReadAllText(configurationPath));
        }
        catch (JsonException ex)
        {
            throw new InvalidDataException("The restored database setup configuration is not valid JSON.", ex);
        }

        var database = root?["Database"] as JsonObject
                       ?? throw new InvalidDataException("The restored setup configuration does not contain a Database object.");
        var provider = database["Provider"]?.GetValue<string>();
        if (!string.Equals(provider, "SQLite", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("The restored setup configuration is not configured for SQLite.");

        var currentConnectionString = database["ConnectionString"]?.GetValue<string>();
        SqliteConnectionStringBuilder builder;
        try
        {
            builder = string.IsNullOrWhiteSpace(currentConnectionString)
                ? new SqliteConnectionStringBuilder()
                : new SqliteConnectionStringBuilder(currentConnectionString);
        }
        catch (ArgumentException ex)
        {
            throw new InvalidDataException("The restored SQLite connection string is invalid.", ex);
        }

        builder.DataSource = Path.GetFullPath(databasePath);
        database["ConnectionString"] = builder.ToString();
        database["SetupComplete"] = true;
        File.WriteAllText(configurationPath, root!.ToJsonString(new JsonSerializerOptions { WriteIndented = true }));
    }

    private static void ValidateSqliteConfiguration(string configurationPath, string databasePath)
    {
        using var document = JsonDocument.Parse(File.ReadAllText(configurationPath));
        var database = document.RootElement.GetProperty("Database");
        if (!database.GetProperty("SetupComplete").GetBoolean())
            throw new InvalidDataException("The restored database setup configuration is not marked complete.");
        if (!string.Equals(database.GetProperty("Provider").GetString(), "SQLite", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("The restored database provider is not SQLite.");

        var builder = new SqliteConnectionStringBuilder(database.GetProperty("ConnectionString").GetString());
        if (!string.Equals(Path.GetFullPath(builder.DataSource), Path.GetFullPath(databasePath), StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("The restored SQLite configuration does not point to the restored database file.");
    }

    private static void RollBack(
        string targetData,
        string targetConfig,
        string rollbackData,
        string rollbackConfig,
        bool originalDataMoved,
        bool originalConfigMoved,
        bool restoredDataInstalled,
        bool restoredConfigInstalled,
        string keyPath,
        string rollbackKeyPath,
        bool hadOriginalKey,
        bool keyMutationStarted)
    {
        if (restoredDataInstalled && Directory.Exists(targetData)) Directory.Delete(targetData, recursive: true);
        if (restoredConfigInstalled && Directory.Exists(targetConfig)) Directory.Delete(targetConfig, recursive: true);
        if (originalDataMoved && Directory.Exists(rollbackData)) Directory.Move(rollbackData, targetData);
        if (originalConfigMoved && Directory.Exists(rollbackConfig)) Directory.Move(rollbackConfig, targetConfig);

        if (!keyMutationStarted) return;
        if (!hadOriginalKey)
        {
            if (File.Exists(keyPath)) File.Delete(keyPath);
            return;
        }

        if (!File.Exists(rollbackKeyPath))
            throw new InvalidOperationException("Restore rollback could not locate the original secret-protection key snapshot.");

        var key = SecretProtectionStorage.ReadAndValidateKey(rollbackKeyPath);
        try
        {
            SecretProtectionStorage.WriteKeyAtomically(keyPath, key, replaceExisting: File.Exists(keyPath));
        }
        finally
        {
            CryptographicOperations.ZeroMemory(key);
        }
    }

    private static void WriteRestoreProvenance(
        string applicationRoot,
        string backupPath,
        RestorePlan plan,
        string databasePath,
        SecretRecoveryInstallStatus keyStatus)
    {
        var text = string.Join(Environment.NewLine, new[]
        {
            "AI WordPress Manager Offline Restore",
            $"RestoredAtUtc: {DateTime.UtcNow:O}",
            $"BackupFile: {Path.GetFileName(backupPath)}",
            $"BackupCreatedAtUtc: {plan.Manifest.CreatedAtUtc:O}",
            $"ManifestVersion: {plan.Manifest.Version}",
            $"ManagedFiles: {plan.Manifest.Files.Count}",
            $"DatabaseFile: {Path.GetFileName(databasePath)}",
            $"SecretKeyStatus: {keyStatus}",
            "DatabaseVerification: PRAGMA quick_check = ok"
        });
        File.WriteAllText(Path.Combine(applicationRoot, "AIMW-LAST-OFFLINE-RESTORE.txt"), text);
    }

    private static string ResolveLocalApplicationData(string? applicationRoot, string? localApplicationData)
    {
        if (!string.IsNullOrWhiteSpace(localApplicationData))
            return Path.GetFullPath(localApplicationData);

        if (!string.IsNullOrWhiteSpace(applicationRoot))
        {
            var fullRoot = Path.GetFullPath(applicationRoot);
            if (!string.Equals(Path.GetFileName(fullRoot.TrimEnd(Path.DirectorySeparatorChar)), "AIWordPressManager", StringComparison.OrdinalIgnoreCase))
                throw new ArgumentException("When --application-root is custom, --local-app-data must also be supplied so the secret-key location is unambiguous.");
            return Directory.GetParent(fullRoot)?.FullName
                   ?? throw new InvalidOperationException("The custom application root does not have a parent directory.");
        }

        var resolved = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
        if (string.IsNullOrWhiteSpace(resolved))
            throw new InvalidOperationException("LocalApplicationData could not be resolved. Supply --local-app-data explicitly.");
        return resolved;
    }

    private static string ResolveApplicationRoot(string? applicationRoot, string localApplicationData) =>
        string.IsNullOrWhiteSpace(applicationRoot)
            ? Path.Combine(localApplicationData, "AIWordPressManager")
            : Path.GetFullPath(applicationRoot);

    private static string NormalizeRelativePath(string value) => value.Replace('\\', '/').Trim();

    private static void ValidateManagedRelativePath(string relativePath)
    {
        if (string.IsNullOrWhiteSpace(relativePath) ||
            relativePath.StartsWith('/') ||
            relativePath.Contains(':') ||
            relativePath.Contains('\0'))
            throw new InvalidDataException($"Unsafe managed path: {relativePath}");

        var parts = relativePath.Split('/', StringSplitOptions.None);
        if (parts.Any(x => string.IsNullOrWhiteSpace(x) || x is "." or ".."))
            throw new InvalidDataException($"Unsafe managed path: {relativePath}");
        if (!relativePath.StartsWith("Data/", StringComparison.OrdinalIgnoreCase) &&
            !relativePath.StartsWith("Config/", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException($"Restore path is outside the managed Data/Config scopes: {relativePath}");
    }

    private static string GetSafeTargetPath(string root, string relativePath)
    {
        ValidateManagedRelativePath(relativePath);
        var fullRoot = Path.GetFullPath(root).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        var candidate = Path.GetFullPath(Path.Combine(root, relativePath.Replace('/', Path.DirectorySeparatorChar)));
        if (!candidate.StartsWith(fullRoot, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException($"Restore path escaped the target root: {relativePath}");
        return candidate;
    }

    private static void DeleteDirectoryBestEffort(string path)
    {
        try
        {
            if (Directory.Exists(path)) Directory.Delete(path, recursive: true);
        }
        catch
        {
            // Provenance and rollback state have already been resolved; cleanup is best effort only.
        }
    }

    private static readonly JsonSerializerOptions JsonOptions = new() { PropertyNameCaseInsensitive = true };

    private sealed record RestorePlan(RestoreManifest Manifest);
    private sealed record RestoreManifest(
        int Version,
        DateTime CreatedAtUtc,
        string? Note,
        string MachineName,
        IReadOnlyList<RestoreManifestFile> Files,
        string? DatabaseProvider,
        string? SecretRecoveryMode,
        string? WrappedSecretKeyEnvelope);
    private sealed record RestoreManifestFile(
        string Name,
        long SizeBytes,
        string? Sha256,
        string? RelativePath,
        string Kind);
}

public sealed record OfflineApplicationRestoreResult(
    string BackupFile,
    string ApplicationRoot,
    string DatabasePath,
    int ManagedFileCount,
    DateTime BackupCreatedAtUtc,
    SecretRecoveryInstallStatus SecretKeyStatus,
    string Message);
