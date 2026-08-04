using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class BackupRecord : Entity
{
    private BackupRecord() { }

    public BackupRecord(string filePath, long fileSizeBytes, bool isVerified, DateTime utcNow)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(filePath);
        FilePath = filePath;
        FileSizeBytes = fileSizeBytes;
        IsVerified = isVerified;
        CreatedAtUtc = utcNow;
        UpdatedAtUtc = utcNow;
    }

    public string FilePath { get; private set; } = string.Empty;
    public long FileSizeBytes { get; private set; }
    public bool IsVerified { get; private set; }
}
