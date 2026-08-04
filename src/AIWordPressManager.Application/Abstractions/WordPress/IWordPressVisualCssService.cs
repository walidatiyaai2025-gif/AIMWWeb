using AIWordPressManager.Application.Common.Results;

namespace AIWordPressManager.Application.Abstractions.WordPress;

public interface IWordPressVisualCssService
{
    Task<Result<BridgeDiagnosticsReport>> RunDiagnosticsAsync(
        Guid siteId,
        CancellationToken cancellationToken = default);

    Task<Result<VisualCssCapabilityResult>> CheckCapabilityAsync(
        Guid siteId,
        CancellationToken cancellationToken = default);

    Task<Result<VisualCssValidationResult>> ValidateAsync(
        Guid siteId,
        VisualCssValidationRequest request,
        CancellationToken cancellationToken = default);

    Task<Result<VisualCssExecutionResult>> ApplyAsync(
        Guid siteId,
        VisualCssExecutionRequest request,
        CancellationToken cancellationToken = default);

    Task<Result<VisualCssExecutionResult>> RollbackAsync(
        Guid siteId,
        VisualCssRollbackRequest request,
        CancellationToken cancellationToken = default);

    Task<Result<VisualCssHistoryResult>> GetHistoryAsync(
        Guid siteId,
        CancellationToken cancellationToken = default);

    Task<Result<VisualCssExecutionResult>> RollbackHistoryAsync(
        Guid siteId,
        string changeId,
        CancellationToken cancellationToken = default);
}

public sealed record VisualCssValidationRequest(
    string PageUrl,
    string Selector,
    string CssDeclarations);

public sealed record VisualCssValidationResult(
    bool IsValid,
    string Message,
    string NormalizedSelector,
    string NormalizedCss,
    string ActiveStylesheet,
    string ManagedCssChecksum,
    int ManagedRuleCount,
    int HttpStatusCode,
    long DurationMilliseconds,
    string ResponseBody);

public sealed record VisualCssExecutionRequest(
    string PageUrl,
    string Selector,
    string CssDeclarations,
    string? ExpectedComputedStyle,
    string? BeforeScreenshotPath);

public sealed record VisualCssRollbackRequest(
    string ChangeId,
    string RollbackToken);

public sealed record VisualCssCapabilityResult(
    bool BridgeAvailable,
    bool CanEditThemeOptions,
    string PluginVersion,
    string ActiveStylesheet,
    string Message);

public sealed record VisualCssExecutionResult(
    bool Succeeded,
    string ChangeId,
    string Message,
    string Selector,
    string CssDeclarations,
    string PreviousManagedCss,
    string AppliedManagedCss,
    string RollbackToken,
    DateTimeOffset ExecutedAtUtc,
    int HttpStatusCode,
    long DurationMilliseconds,
    string ResponseBody);

public sealed record BridgeDiagnosticCheck(
    string Name,
    bool Succeeded,
    string Status,
    string Details,
    long DurationMilliseconds);

public sealed record BridgeDiagnosticsReport(
    bool IsReady,
    string Summary,
    string PluginVersion,
    string WordPressVersion,
    string PhpVersion,
    string ActiveTheme,
    string ActiveStylesheet,
    bool CanEditPosts,
    bool CanUploadFiles,
    bool CanEditThemeOptions,
    bool YoastDetected,
    bool RankMathDetected,
    bool ElementorDetected,
    bool DiviDetected,
    DateTimeOffset TestedAtUtc,
    IReadOnlyList<BridgeDiagnosticCheck> Checks);


public sealed record VisualCssHistoryItem(
    string ChangeId,
    string PageUrl,
    string Selector,
    string CssDeclarations,
    string Status,
    string ActiveStylesheet,
    DateTimeOffset ExecutedAtUtc,
    DateTimeOffset? RolledBackAtUtc,
    string ExecutedBy);

public sealed record VisualCssHistoryResult(
    string PluginVersion,
    string ActiveStylesheet,
    int ManagedRuleCount,
    string ManagedCssChecksum,
    IReadOnlyList<VisualCssHistoryItem> Items,
    int HttpStatusCode,
    long DurationMilliseconds,
    string ResponseBody);
