namespace AIWordPressManager.Application.Abstractions.Persistence;

public interface IDatabaseBackupService
{
    Task<string> CreateBackupAsync(CancellationToken cancellationToken = default);

    Task<DatabaseRestorePlan> PrepareRestoreAsync(
        string backupFilePath,
        int currentProcessId,
        string executablePath,
        CancellationToken cancellationToken = default);
}

public sealed record DatabaseRestorePlan(
    string BackupFilePath,
    string SafetyBackupPath,
    string RestoreScriptPath,
    bool RequiresApplicationShutdown);
