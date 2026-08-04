using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class DatabaseVersion : Entity
{
    private DatabaseVersion() { }

    public DatabaseVersion(string migrationId, DateTime appliedAtUtc)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(migrationId);
        MigrationId = migrationId.Trim();
        AppliedAtUtc = appliedAtUtc;
    }

    public string MigrationId { get; private set; } = string.Empty;
    public DateTime AppliedAtUtc { get; private set; }
}
