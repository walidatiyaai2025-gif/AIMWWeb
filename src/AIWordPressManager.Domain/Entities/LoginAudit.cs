using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class LoginAudit : Entity
{
    private LoginAudit() { }

    public LoginAudit(
        string userName,
        bool succeeded,
        string reason,
        string? ipAddress,
        string? userAgent,
        DateTime utcNow)
    {
        UserName = string.IsNullOrWhiteSpace(userName) ? "(empty)" : userName.Trim();
        Succeeded = succeeded;
        Reason = string.IsNullOrWhiteSpace(reason) ? (succeeded ? "Success" : "Failed") : reason.Trim();
        IpAddress = string.IsNullOrWhiteSpace(ipAddress) ? "Unknown" : ipAddress.Trim();
        UserAgent = string.IsNullOrWhiteSpace(userAgent) ? "Unknown" : userAgent.Trim();
        AttemptedAtUtc = utcNow;
        CreatedAtUtc = utcNow;
        UpdatedAtUtc = utcNow;
    }

    public string UserName { get; private set; } = string.Empty;
    public bool Succeeded { get; private set; }
    public string Reason { get; private set; } = string.Empty;
    public string IpAddress { get; private set; } = string.Empty;
    public string UserAgent { get; private set; } = string.Empty;
    public DateTime AttemptedAtUtc { get; private set; }
}
