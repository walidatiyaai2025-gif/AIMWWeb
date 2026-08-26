from pathlib import Path

BRANCH = "lead/rel003-final-offline-recovery-closure"

backup_path = Path("src/AIWordPressManager.Web/Services/BackupManagementService.cs")
text = backup_path.read_text(encoding="utf-8")
start = text.index("    public BackupFileInfo CreateBackup(")
end = text.index("    public BackupVerificationResult Verify(", start)
new_method = r'''    public BackupFileInfo CreateBackup(string? note = null, string? wrappedSecretKeyEnvelope = null)
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
            var snapshotRoot = Path.Combine(_backupDirectory, $".sqlite-snapshot-{Guid.NewGuid():N}");
            var databaseProvider = ReadConfiguredDatabaseProvider();
            if (!string.IsNullOrWhiteSpace(databaseProvider) &&
                !databaseProvider.Equals("SQLite", StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidOperationException(
                    $"Database provider '{databaseProvider}' requires a provider-native backup path. Backup creation is blocked rather than producing an incomplete archive.");
            }

            try
            {
                var liveSources = EnumerateManagedFiles().ToList();
                var databaseSources = liveSources.Where(x => x.Kind == BackupContentKind.Database).ToList();
                if (databaseSources.Count != 1)
                    throw new InvalidOperationException($"Exactly one application SQLite database is required for a recoverable backup; found {databaseSources.Count}.");

                var sources = CreateStableBackupSources(liveSources, snapshotRoot);
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
                    databaseProvider ?? "SQLite",
                    secretRecoveryMode,
                    normalizedWrappedEnvelope);

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
                    $"Created verified transactional SQLite backup containing {manifestFiles.Count} managed file(s), including {info.DatabaseCount} database snapshot file(s) and {manifestFiles.Count(x => x.Kind == nameof(BackupContentKind.Configuration))} configuration file(s). Secret recovery material: {(normalizedWrappedEnvelope is null ? "not included" : "wrapped envelope included")}.");
                return info;
            }
            catch (Exception ex)
            {
                if (File.Exists(target)) File.Delete(target);
                WriteAudit("Create", fileName, false, SanitizeAuditMessage(ex.Message));
                throw;
            }
            finally
            {
                DeleteDirectoryBestEffort(snapshotRoot);
            }
        }
    }

'''
text = text[:start] + new_method + text[end:]
marker = "    private IEnumerable<ManagedBackupSource> EnumerateManagedFiles()\n"
if marker not in text:
    raise SystemExit("Backup helper insertion marker missing")
helpers = r'''    private static IReadOnlyList<ManagedBackupSource> CreateStableBackupSources(
        IReadOnlyList<ManagedBackupSource> liveSources,
        string snapshotRoot)
    {
        Directory.CreateDirectory(snapshotRoot);
        var stable = new List<ManagedBackupSource>(liveSources.Count);
        try
        {
            foreach (var source in liveSources)
            {
                if (source.Kind == BackupContentKind.DatabaseSidecar)
                    continue;

                if (source.Kind != BackupContentKind.Database)
                {
                    stable.Add(source);
                    continue;
                }

                var snapshotPath = Path.Combine(snapshotRoot, $"{Guid.NewGuid():N}-{Path.GetFileName(source.SourcePath)}");
                CreateSqliteSnapshot(source.SourcePath, snapshotPath);
                stable.Add(source with { SourcePath = snapshotPath });
            }

            return stable;
        }
        catch
        {
            DeleteDirectoryBestEffort(snapshotRoot);
            throw;
        }
    }

    private static void CreateSqliteSnapshot(string sourcePath, string snapshotPath)
    {
        if (!File.Exists(sourcePath))
            throw new FileNotFoundException("The configured SQLite database was not found during backup.", sourcePath);

        var sourceBuilder = new SqliteConnectionStringBuilder
        {
            DataSource = Path.GetFullPath(sourcePath),
            Mode = SqliteOpenMode.ReadOnly
        };
        var destinationBuilder = new SqliteConnectionStringBuilder
        {
            DataSource = Path.GetFullPath(snapshotPath),
            Mode = SqliteOpenMode.ReadWriteCreate
        };

        using (var source = new SqliteConnection(sourceBuilder.ToString()))
        using (var destination = new SqliteConnection(destinationBuilder.ToString()))
        {
            source.Open();
            destination.Open();
            source.BackupDatabase(destination);
        }

        ValidateSqliteSnapshot(snapshotPath);
    }

    private static void ValidateSqliteSnapshot(string snapshotPath)
    {
        var builder = new SqliteConnectionStringBuilder
        {
            DataSource = Path.GetFullPath(snapshotPath),
            Mode = SqliteOpenMode.ReadOnly
        };
        using var connection = new SqliteConnection(builder.ToString());
        connection.Open();
        using var command = connection.CreateCommand();
        command.CommandText = "PRAGMA quick_check;";
        var result = Convert.ToString(command.ExecuteScalar());
        if (!string.Equals(result, "ok", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException($"The SQLite backup snapshot failed quick_check: {result ?? "no result"}.");
    }

    private static void DeleteDirectoryBestEffort(string path)
    {
        try
        {
            if (Directory.Exists(path)) Directory.Delete(path, recursive: true);
        }
        catch
        {
            // Backup result has already been committed or rejected; temp cleanup is best effort only.
        }
    }

'''
text = text.replace(marker, helpers + marker, 1)
backup_path.write_text(text, encoding="utf-8")

page_path = Path("src/AIWordPressManager.Web/Components/Pages/BackupRestore.razor")
page = page_path.read_text(encoding="utf-8")
needle = '@rendermode InteractiveServer\n'
attribute = '@attribute [Microsoft.AspNetCore.Authorization.Authorize(Roles = "Administrator")]\n'
if attribute not in page:
    if needle not in page:
        raise SystemExit("BackupRestore render-mode marker missing")
    page = page.replace(needle, needle + attribute, 1)
page_path.write_text(page, encoding="utf-8")
