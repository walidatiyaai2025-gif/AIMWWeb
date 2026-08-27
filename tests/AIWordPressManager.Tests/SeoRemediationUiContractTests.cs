namespace AIWordPressManager.Tests;

public sealed class SeoRemediationUiContractTests
{
    [Fact]
    public void Canonical_SEO_workspace_exposes_review_and_real_execution_controls()
    {
        var source = ReadPage();

        Assert.Contains("@inject SeoRemediationWebService RemediationService", source, StringComparison.Ordinal);
        Assert.Contains("data-testid=\"seo-remediation-workspace\"", source, StringComparison.Ordinal);
        Assert.Contains("data-testid=\"seo-generate-all\"", source, StringComparison.Ordinal);
        Assert.Contains("data-testid=\"seo-apply-selected\"", source, StringComparison.Ordinal);
        Assert.Contains("data-testid=\"seo-apply-all-safe\"", source, StringComparison.Ordinal);
        Assert.Contains("data-testid=\"seo-retry-failed\"", source, StringComparison.Ordinal);
        Assert.Contains("data-testid=\"proposal-current-@proposalId\"", source, StringComparison.Ordinal);
        Assert.Contains("data-testid=\"proposal-suggested-@proposalId\"", source, StringComparison.Ordinal);
        Assert.Contains("data-testid=\"proposal-apply-@proposalId\"", source, StringComparison.Ordinal);
        Assert.Contains("await RemediationService.GenerateAsync(Id)", source, StringComparison.Ordinal);
        Assert.Contains("await RemediationService.ApplyAsync(Id, proposalId)", source, StringComparison.Ordinal);
        Assert.Contains("RemediationService.ApplySelectedAsync", source, StringComparison.Ordinal);
        Assert.Contains("RemediationService.ApplyAllSafeAsync", source, StringComparison.Ordinal);
        Assert.Contains("RemediationService.RetryFailedAsync", source, StringComparison.Ordinal);
        Assert.Contains("await RemediationService.UndoAsync", source, StringComparison.Ordinal);
    }

    [Fact]
    public void Workspace_makes_verification_safety_and_partial_results_visible_in_both_languages()
    {
        var source = ReadPage();

        Assert.Contains("Success appears only after WordPress persistence and re-read verification.", source, StringComparison.Ordinal);
        Assert.Contains("لا يظهر النجاح إلا بعد حفظ القيمة وإعادة قراءتها من ووردبريس.", source, StringComparison.Ordinal);
        Assert.Contains("Review required", source, StringComparison.Ordinal);
        Assert.Contains("يتطلب مراجعة", source, StringComparison.Ordinal);
        Assert.Contains("_lastBulkResult.Failed", source, StringComparison.Ordinal);
        Assert.Contains("_lastBulkResult.Conflicted", source, StringComparison.Ordinal);
        Assert.Contains("_lastBulkResult.ReviewRequired", source, StringComparison.Ordinal);
        Assert.Contains("proposal.State.ToString() == \"Verified\"", source, StringComparison.Ordinal);
        Assert.Contains("Undo unavailable without verified audit evidence", source, StringComparison.Ordinal);
    }

    private static string ReadPage()
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "AIWordPressManager.Web.sln")))
                return File.ReadAllText(Path.Combine(current.FullName, "src", "AIWordPressManager.Web", "Components", "Pages", "SeoManager.razor"));
            current = current.Parent;
        }

        throw new DirectoryNotFoundException("Could not locate AIMWWeb repository root from the test output directory.");
    }
}
