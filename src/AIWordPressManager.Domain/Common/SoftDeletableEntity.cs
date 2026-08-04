namespace AIWordPressManager.Domain.Common;

public abstract class SoftDeletableEntity : Entity
{
    public bool IsDeleted { get; private set; }

    public DateTime? DeletedAtUtc { get; private set; }

    public void SoftDelete(DateTime utcNow)
    {
        if (IsDeleted)
        {
            return;
        }

        IsDeleted = true;
        DeletedAtUtc = utcNow;
        MarkUpdated(utcNow);
    }

    public void Restore(DateTime utcNow)
    {
        if (!IsDeleted)
        {
            return;
        }

        IsDeleted = false;
        DeletedAtUtc = null;
        MarkUpdated(utcNow);
    }
}
