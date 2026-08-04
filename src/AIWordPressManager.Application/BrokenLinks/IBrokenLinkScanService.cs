using AIWordPressManager.Application.Common.Results;

namespace AIWordPressManager.Application.BrokenLinks;

public interface IBrokenLinkScanService
{
    Task<Result<BrokenLinkScanSummary>> LoadLatestAsync(Guid siteId, CancellationToken cancellationToken = default);
    Task<Result<BrokenLinkScanSummary>> RunAsync(Guid siteId, IProgress<BrokenLinkScanProgress>? progress = null, CancellationToken cancellationToken = default);
}

public sealed record BrokenLinkScanProgress(int Percent, string CurrentStep);
public sealed record BrokenLinkDto(string SourceTitle, string SourceUrl, string TargetUrl, int? StatusCode, string Status, string? ErrorMessage);
public sealed record BrokenLinkScanSummary(int CheckedLinks, int BrokenLinks, int Redirects, int HealthyLinks, IReadOnlyList<BrokenLinkDto> Results, DateTimeOffset CompletedAt);
