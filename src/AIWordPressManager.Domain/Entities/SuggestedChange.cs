using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class SuggestedChange : Entity
{
    private SuggestedChange() { }

    public SuggestedChange(Guid siteId, string fingerprint, string sourceType, string objectType, string objectId,
        string changeType, string currentValue, string proposedValue, string reason, double confidence,
        string riskLevel, bool requiresBackup, bool requiresStaging, DateTime utcNow)
    {
        SiteId = siteId;
        Fingerprint = fingerprint;
        SourceType = sourceType;
        ObjectType = objectType;
        ObjectId = objectId;
        ChangeType = changeType;
        CurrentValue = currentValue;
        ProposedValue = proposedValue;
        Reason = reason;
        Confidence = Math.Clamp(confidence, 0, 1);
        RiskLevel = riskLevel;
        RequiresBackup = requiresBackup;
        RequiresStaging = requiresStaging;
        ApprovalStatus = "Pending";
        ExecutionStatus = "NotStarted";
        CreatedAtUtc = utcNow;
        UpdatedAtUtc = utcNow;
    }

    public Guid SiteId { get; private set; }
    public Site Site { get; private set; } = null!;
    public string Fingerprint { get; private set; } = string.Empty;
    public string SourceType { get; private set; } = string.Empty;
    public string ObjectType { get; private set; } = string.Empty;
    public string ObjectId { get; private set; } = string.Empty;
    public string ChangeType { get; private set; } = string.Empty;
    public string CurrentValue { get; private set; } = string.Empty;
    public string ProposedValue { get; private set; } = string.Empty;
    public string Reason { get; private set; } = string.Empty;
    public double Confidence { get; private set; }
    public string RiskLevel { get; private set; } = "Low";
    public bool RequiresBackup { get; private set; }
    public bool RequiresStaging { get; private set; }
    public string ApprovalStatus { get; private set; } = "Pending";
    public string ExecutionStatus { get; private set; } = "NotStarted";
    public DateTime? ApprovedAtUtc { get; private set; }
    public DateTime? RejectedAtUtc { get; private set; }

    public void Approve(DateTime utcNow)
    {
        ApprovalStatus = "Approved";
        ApprovedAtUtc = utcNow;
        RejectedAtUtc = null;
        MarkUpdated(utcNow);
    }

    public void Reject(DateTime utcNow)
    {
        ApprovalStatus = "Rejected";
        RejectedAtUtc = utcNow;
        ApprovedAtUtc = null;
        MarkUpdated(utcNow);
    }


    public void MarkExecuting(DateTime utcNow)
    {
        ExecutionStatus = "Executing";
        MarkUpdated(utcNow);
    }

    public void MarkExecuted(DateTime utcNow)
    {
        ExecutionStatus = "Executed";
        MarkUpdated(utcNow);
    }

    public void MarkExecutionFailed(DateTime utcNow)
    {
        ExecutionStatus = "Failed";
        MarkUpdated(utcNow);
    }

    public void MarkRolledBack(DateTime utcNow)
    {
        ExecutionStatus = "RolledBack";
        MarkUpdated(utcNow);
    }

    public void PrepareForExecution(string changeType, string proposedValue, string riskLevel, bool requiresBackup, bool requiresStaging, DateTime utcNow)
    {
        ChangeType = changeType;
        ProposedValue = proposedValue;
        RiskLevel = riskLevel;
        RequiresBackup = requiresBackup;
        RequiresStaging = requiresStaging;
        ApprovalStatus = "Pending";
        ApprovedAtUtc = null;
        RejectedAtUtc = null;
        ExecutionStatus = "NotStarted";
        MarkUpdated(utcNow);
    }

    public void ReturnToPending(DateTime utcNow)
    {
        ApprovalStatus = "Pending";
        ApprovedAtUtc = null;
        RejectedAtUtc = null;
        MarkUpdated(utcNow);
    }
}
