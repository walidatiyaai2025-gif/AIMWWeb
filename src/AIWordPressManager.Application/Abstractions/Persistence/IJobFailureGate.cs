namespace AIWordPressManager.Application.Abstractions.Persistence;

public sealed record JobGateDecision(bool CanRun, DateTime? ResumeAtUtc, string Message)
{
    public static JobGateDecision Allowed() => new(true, null, string.Empty);
}

public interface IJobFailureGate
{
    Task<JobGateDecision> CanStartAsync(Guid siteId, string jobType, CancellationToken cancellationToken = default);
}
