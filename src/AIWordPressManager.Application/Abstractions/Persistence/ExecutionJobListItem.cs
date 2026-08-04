namespace AIWordPressManager.Application.Abstractions.Persistence;

public sealed record ExecutionJobListItem(
    Guid Id,
    Guid SiteId,
    string SiteName,
    string JobType,
    string Status,
    int ProgressPercent,
    string CurrentStep,
    string? ErrorDetails,
    DateTime StartedAtUtc,
    DateTime? CompletedAtUtc,
    DateTime UpdatedAtUtc);
