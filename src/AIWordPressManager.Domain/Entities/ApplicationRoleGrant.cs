using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class ApplicationRoleGrant : Entity
{
    private ApplicationRoleGrant() { }

    public ApplicationRoleGrant(Guid applicationRoleId, string permission, DateTime utcNow)
    {
        if (applicationRoleId == Guid.Empty) throw new ArgumentException("Application role is required.", nameof(applicationRoleId));
        ArgumentException.ThrowIfNullOrWhiteSpace(permission);

        ApplicationRoleId = applicationRoleId;
        Permission = permission.Trim();
        MarkUpdated(utcNow);
    }

    public Guid ApplicationRoleId { get; private set; }
    public string Permission { get; private set; } = string.Empty;
}
