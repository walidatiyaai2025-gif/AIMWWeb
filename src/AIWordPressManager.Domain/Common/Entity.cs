namespace AIWordPressManager.Domain.Common;

public abstract class Entity
{
    public Guid Id { get; protected set; } = Guid.NewGuid();

    public DateTime CreatedAtUtc { get; protected set; } = DateTime.UtcNow;

    public DateTime UpdatedAtUtc { get; protected set; } = DateTime.UtcNow;

    public byte[] ConcurrencyToken { get; protected set; } = Guid.NewGuid().ToByteArray();

    protected void MarkUpdated(DateTime utcNow)
    {
        UpdatedAtUtc = utcNow;
        ConcurrencyToken = Guid.NewGuid().ToByteArray();
    }
}
