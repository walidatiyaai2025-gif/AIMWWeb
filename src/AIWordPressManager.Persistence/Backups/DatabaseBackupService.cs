using System.Text;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.Persistence;
using AIWordPressManager.Domain.Entities;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Persistence.Backups;

public sealed class DatabaseBackupService(
    AppDbContext dbContext,
    IApplicationPathService paths,
    IClock clock,
    ILogger<DatabaseBackupService> logger) : IDatabaseBackupService
{
    public async Task<string> CreateBackupAsync(CancellationToken cancellationToken = default)
    {
        await dbContext.Database.ExecuteSqlRawAsync("PRAGMA wal_checkpoint(FULL);", cancellationToken);

        var timestamp = clock.UtcNow.ToLocalTime().ToString("yyyy-MM-dd_HHmmssfff");
        var target = Path.Combine(paths.GetBackupsDirectory(), $"AIWordPressManager_{timestamp}.db");
        var source = paths.GetDatabasePath();
        Directory.CreateDirectory(paths.GetBackupsDirectory());
        File.Copy(source, target, overwrite: false);

        await VerifyDatabaseAsync(target, cancellationToken);

        var info = new FileInfo(target);
        dbContext.Backups.Add(new BackupRecord(target, info.Length, true, clock.UtcNow));
        await dbContext.SaveChangesAsync(cancellationToken);
        logger.LogInformation("Database backup created at {BackupPath}.", target);
        return target;
    }

    public async Task<DatabaseRestorePlan> PrepareRestoreAsync(
        string backupFilePath,
        int currentProcessId,
        string executablePath,
        CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(backupFilePath) || !File.Exists(backupFilePath))
        {
            throw new FileNotFoundException("The selected database backup file does not exist.", backupFilePath);
        }

        if (string.IsNullOrWhiteSpace(executablePath) || !File.Exists(executablePath))
        {
            throw new FileNotFoundException("The application executable could not be located for restart.", executablePath);
        }

        await VerifyDatabaseAsync(backupFilePath, cancellationToken);

        // Always preserve the current database before staging a restore.
        var safetyBackupPath = await CreateBackupAsync(cancellationToken);
        var databasePath = paths.GetDatabasePath();
        var restoreDirectory = Path.Combine(paths.GetBackupsDirectory(), "RestoreJobs");
        Directory.CreateDirectory(restoreDirectory);

        // Copy the chosen source into a stable restore payload so the original file can be moved safely.
        var payloadPath = Path.Combine(
            restoreDirectory,
            $"restore_payload_{clock.UtcNow:yyyyMMdd_HHmmssfff}.db");
        File.Copy(backupFilePath, payloadPath, overwrite: false);
        await VerifyDatabaseAsync(payloadPath, cancellationToken);

        var scriptPath = Path.Combine(
            restoreDirectory,
            $"restore_database_{clock.UtcNow:yyyyMMdd_HHmmssfff}.cmd");

        var script = BuildRestoreScript(
            currentProcessId,
            payloadPath,
            databasePath,
            executablePath);
        await File.WriteAllTextAsync(scriptPath, script, new UTF8Encoding(false), cancellationToken);

        logger.LogWarning(
            "Database restore prepared from {BackupPath}. Safety backup: {SafetyBackupPath}. Script: {ScriptPath}.",
            backupFilePath,
            safetyBackupPath,
            scriptPath);

        return new DatabaseRestorePlan(
            backupFilePath,
            safetyBackupPath,
            scriptPath,
            RequiresApplicationShutdown: true);
    }

    private static async Task VerifyDatabaseAsync(string databasePath, CancellationToken cancellationToken)
    {
        await using var connection = new SqliteConnection($"Data Source={databasePath};Mode=ReadOnly");
        await connection.OpenAsync(cancellationToken);
        await using var command = connection.CreateCommand();
        command.CommandText = "PRAGMA integrity_check;";
        var result = Convert.ToString(await command.ExecuteScalarAsync(cancellationToken));
        if (!string.Equals(result, "ok", StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidOperationException(
                $"SQLite integrity check failed for '{databasePath}'. Result: {result ?? "No result"}.");
        }
    }

    private static string BuildRestoreScript(
        int processId,
        string payloadPath,
        string databasePath,
        string executablePath)
    {
        static string Quote(string value) => $"\"{value.Replace("\"", "\"\"")}\"";

        var walPath = databasePath + "-wal";
        var shmPath = databasePath + "-shm";
        var journalPath = databasePath + "-journal";

        return string.Join(Environment.NewLine,
        [
            "@echo off",
            "setlocal",
            $"set APP_PID={processId}",
            ":wait_for_app",
            "tasklist /FI \"PID eq %APP_PID%\" 2>NUL | find \"%APP_PID%\" >NUL",
            "if not errorlevel 1 (",
            "  timeout /t 1 /nobreak >NUL",
            "  goto wait_for_app",
            ")",
            $"del /q {Quote(walPath)} 2>NUL",
            $"del /q {Quote(shmPath)} 2>NUL",
            $"del /q {Quote(journalPath)} 2>NUL",
            $"copy /y {Quote(payloadPath)} {Quote(databasePath)} >NUL",
            "if errorlevel 1 (",
            "  echo Database restore failed. Press any key to close.",
            "  pause >NUL",
            "  exit /b 1",
            ")",
            $"del /q {Quote(payloadPath)} 2>NUL",
            $"start \"\" {Quote(executablePath)}",
            "del /q \"%~f0\"",
            "endlocal"
        ]);
    }
}
