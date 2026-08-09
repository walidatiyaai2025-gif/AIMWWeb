using AIWordPressManager.Application.SeoAudit;

namespace AIWordPressManager.Web.Services;

public sealed class SeoAuditExecutionService(
    SeoAnalysisWebService seoAnalysis,
    ISeoAuditService auditPersistence,
    ExecutionOperationTracker executionTracker,
    AppNotificationService notifications,
    CurrentUserContext currentUser)
{
    public async Task<SeoAuditExecutionResult> RunAsync(
        Guid siteId,
        CancellationToken cancellationToken = default)
    {
        var ownerUserId = currentUser.RequireUserId();
        var jobId = executionTracker.Start(
            ownerUserId,
            siteId,
            "Run SEO audit",
            "SEO Audit",
            siteId.ToString(),
            5);

        notifications.Info(
            "The SEO audit has started. Progress is available in Execution Center.",
            "SEO Audit");

        try
        {
            executionTracker.Report(jobId, 1, 5, "Loading synchronized WordPress content from SQLite.");

            var analysis = await seoAnalysis.AnalyzeAsync(
                siteId,
                cancellationToken: cancellationToken);

            if (analysis is null)
                throw new InvalidOperationException("Site not found.");

            executionTracker.Report(
                jobId,
                2,
                5,
                $"Analyzed {analysis.Summary.Total} posts and pages.");

            var issueCounts = analysis.Items
                .SelectMany(x => x.Issues)
                .GroupBy(x => x.Code, StringComparer.OrdinalIgnoreCase)
                .ToDictionary(
                    x => x.Key,
                    x => x.Sum(issue => issue.Count),
                    StringComparer.OrdinalIgnoreCase);

            executionTracker.Report(
                jobId,
                3,
                5,
                $"Found {analysis.Summary.TotalIssues} SEO issue groups. Average score: {analysis.Summary.AverageScore}.");

            var capturedAt = DateTimeOffset.UtcNow;
            var captureIssues = analysis.Items
                .SelectMany(item => item.Issues.Select(issue => new SeoAuditCaptureIssue(
                    issue.Severity,
                    issue.Code,
                    item.ContentType,
                    item.WordPressId,
                    item.Title,
                    DescribeIssue(issue),
                    item.Link)))
                .ToList();

            var saved = await auditPersistence.SaveAsync(
                siteId,
                ownerUserId,
                new SeoAuditCapture(
                    analysis.Summary.AverageScore,
                    analysis.Summary.Total,
                    captureIssues,
                    capturedAt),
                cancellationToken);

            if (saved.IsFailure)
                throw new InvalidOperationException(saved.Error.Message);

            executionTracker.Report(jobId, 4, 5, "Saved the SEO audit snapshot and current issue set.");

            var history = await auditPersistence.LoadHistoryAsync(siteId, ownerUserId, 12, cancellationToken);
            if (history.IsFailure)
                throw new InvalidOperationException(history.Error.Message);

            var message =
                $"SEO audit completed for {analysis.SiteName}: " +
                $"{analysis.Summary.Total} items, average score {analysis.Summary.AverageScore}, " +
                $"{analysis.Summary.TotalIssues} issue groups.";

            executionTracker.Complete(jobId, 5, 5, message);
            notifications.Success(message, "SEO Audit Completed");

            return new SeoAuditExecutionResult(
                true,
                analysis.SiteName,
                analysis.Summary,
                issueCounts,
                history.Value,
                message,
                capturedAt.UtcDateTime,
                jobId);
        }
        catch (Exception ex)
        {
            executionTracker.Fail(jobId, ex.Message);
            notifications.Error(
                "The SEO audit failed. Open the details for the technical error.",
                "SEO Audit Failed",
                ex.ToString());
            throw;
        }
    }

    public async Task<IReadOnlyList<SeoAuditHistoryPoint>> LoadHistoryAsync(
        Guid siteId,
        int take = 12,
        CancellationToken cancellationToken = default)
    {
        var ownerUserId = currentUser.RequireUserId();
        var history = await auditPersistence.LoadHistoryAsync(siteId, ownerUserId, take, cancellationToken);
        if (history.IsFailure)
            throw new InvalidOperationException(history.Error.Message);
        return history.Value;
    }

    private static string DescribeIssue(SeoIssue issue)
        => issue.Count > 1 ? $"{issue.Code} detected {issue.Count} times." : $"{issue.Code} detected.";
}

public sealed record SeoAuditExecutionResult(
    bool IsSuccess,
    string SiteName,
    SeoSummary Summary,
    IReadOnlyDictionary<string, int> IssueCounts,
    IReadOnlyList<SeoAuditHistoryPoint> History,
    string Message,
    DateTime CompletedAtUtc,
    Guid? ExecutionJobId = null);
