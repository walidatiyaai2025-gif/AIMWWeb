namespace AIWordPressManager.Web.Services;

public sealed class SeoAuditExecutionService(
    SeoAnalysisWebService seoAnalysis,
    ExecutionOperationTracker executionTracker,
    AppNotificationService notifications)
{
    public async Task<SeoAuditExecutionResult> RunAsync(
        Guid siteId,
        CancellationToken cancellationToken = default)
    {
        var jobId = executionTracker.Start(
            "Run SEO audit",
            "SEO Audit",
            siteId.ToString(),
            4);

        notifications.Info(
            "The SEO audit has started. Progress is available in Execution Center.",
            "SEO Audit");

        try
        {
            executionTracker.Report(jobId, 1, 4, "Loading synchronized WordPress content from SQLite.");

            var analysis = await seoAnalysis.AnalyzeAsync(
                siteId,
                cancellationToken: cancellationToken);

            if (analysis is null)
                throw new InvalidOperationException("Site not found.");

            executionTracker.Report(
                jobId,
                2,
                4,
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
                4,
                $"Found {analysis.Summary.TotalIssues} SEO issues. Average score: {analysis.Summary.AverageScore}.");

            var message =
                $"SEO audit completed for {analysis.SiteName}: " +
                $"{analysis.Summary.Total} items, average score {analysis.Summary.AverageScore}, " +
                $"{analysis.Summary.TotalIssues} issues.";

            executionTracker.Complete(jobId, 4, 4, message);
            notifications.Success(message, "SEO Audit Completed");

            return new SeoAuditExecutionResult(
                true,
                analysis.SiteName,
                analysis.Summary,
                issueCounts,
                message,
                DateTime.UtcNow);
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
}

public sealed record SeoAuditExecutionResult(
    bool IsSuccess,
    string SiteName,
    SeoSummary Summary,
    IReadOnlyDictionary<string, int> IssueCounts,
    string Message,
    DateTime CompletedAtUtc);
