using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class ApplicationRoleGrant : Entity
{
    private ApplicationRoleGrant() { }

    public ApplicationRoleGrant(Guid roleId, string permission, DateTime utcNow)
    {
        if (roleId == Guid.Empty) throw new ArgumentException("Role ID is required.", nameof(roleId));
        ArgumentException.ThrowIfNullOrWhiteSpace(permission);
        RoleId = roleId;
        Permission = permission.Trim();
        MarkUpdated(utcNow);
    }

    public Guid RoleId { get; private set; }
    public string Permission { get; private set; } = string.Empty;
}