using AIWordPressManager.Application.Common.Results;

namespace AIWordPressManager.Application.Changes;

public sealed record ApprovedChangeExecutionItem(
    Guid ChangeId,
    string ObjectType,
    string ObjectLabel,
    string ChangeType,
    string CurrentValue,
    string ProposedValue,
    string RiskLevel,
    bool RequiresBackup,
    bool RequiresStaging,
    string ApprovalStatus,
    string ExecutionStatus,
    bool CanApprove,
    bool CanExecute,
    string PreflightMessage,
    string ExecutorName,
    string RouteState,
    string ExecutionPlan,
    string BeforePreview,
    string AfterPreview);

public sealed record ChangeExecutionBatchResult(int Requested, int Executed, int Failed, int Skipped, int Verified);
public sealed record ExecutablePreparationResult(int Requested, int Prepared, int AlreadyExecutable, int Unsupported);

public interface IApprovedChangeExecutionService
{
    Task<IReadOnlyList<ApprovedChangeExecutionItem>> GetApprovedQueueAsync(Guid siteId, CancellationToken cancellationToken = default);
    Task<Result<ExecutablePreparationResult>> PrepareExecutableValuesAsync(Guid siteId, IReadOnlyCollection<Guid> changeIds, CancellationToken cancellationToken = default);
    Task<Result<ChangeExecutionBatchResult>> ExecuteAsync(Guid siteId, IReadOnlyCollection<Guid> changeIds, IProgress<(int Percent, string Step)>? progress = null, CancellationToken cancellationToken = default);
    Task<Result<ChangeExecutionBatchResult>> RollbackAsync(Guid siteId, IReadOnlyCollection<Guid> changeIds, IProgress<(int Percent, string Step)>? progress = null, CancellationToken cancellationToken = default);
}
