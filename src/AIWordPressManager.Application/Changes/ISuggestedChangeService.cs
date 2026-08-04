namespace AIWordPressManager.Application.Changes;

public sealed record SuggestedChangeItem(Guid Id, string SourceType, string ObjectType, string ObjectId,
    string ChangeType, string CurrentValue, string ProposedValue, string Reason, double Confidence,
    string RiskLevel, bool RequiresBackup, bool RequiresStaging, string ApprovalStatus, string ExecutionStatus,
    DateTime CreatedAtUtc)
{
    public string AiProvider
    {
        get
        {
            const string marker = "[AI:";
            var start = Reason.IndexOf(marker, StringComparison.OrdinalIgnoreCase);
            if (start < 0) return "Rules Engine";
            start += marker.Length;
            var end = Reason.IndexOf(']', start);
            return end > start ? Reason[start..end].Trim() : "AI";
        }
    }

    public string CleanReason
    {
        get
        {
            if (!Reason.StartsWith("[AI:", StringComparison.OrdinalIgnoreCase)) return Reason;
            var end = Reason.IndexOf(']');
            return end >= 0 && end + 1 < Reason.Length ? Reason[(end + 1)..].Trim() : Reason;
        }
    }

    public string ConfidenceDisplay => $"{Confidence:P0}";

    public bool CanApplyDirectly =>
        ObjectType == "Content" &&
        ChangeType is "SetTitle" or "SetSlug" or "SetExcerpt" or "SetStatus" &&
        !RequiresStaging &&
        !RiskLevel.Equals("High", StringComparison.OrdinalIgnoreCase) &&
        ExecutionStatus is not "Executed" and not "Executing";
}

public sealed record SuggestedChangeGenerationResult(int Created, int Existing, int TotalPending);

public sealed record VisualSuggestionInput(string ViewportName, string PageTitle, bool HorizontalOverflow,
    int MissingAltImages, int BrokenImages, int SmallTextElements, int SmallTouchTargets,
    IReadOnlyList<string> ConsoleErrors);

public sealed record VisualSuggestionGenerationResult(int Created, int Existing);

public interface ISuggestedChangeService
{
    Task<IReadOnlyList<SuggestedChangeItem>> GetAsync(Guid siteId, string? approvalStatus = null, CancellationToken cancellationToken = default);
    Task<SuggestedChangeGenerationResult> GenerateFromLocalInsightsAsync(Guid siteId, CancellationToken cancellationToken = default);
    Task<VisualSuggestionGenerationResult> CreateFromVisualInspectionAsync(Guid siteId, IReadOnlyCollection<VisualSuggestionInput> inputs, CancellationToken cancellationToken = default);
    Task SetApprovalStatusAsync(Guid changeId, string status, CancellationToken cancellationToken = default);
    Task SetExecutionStatusAsync(Guid changeId, string status, CancellationToken cancellationToken = default);
}
