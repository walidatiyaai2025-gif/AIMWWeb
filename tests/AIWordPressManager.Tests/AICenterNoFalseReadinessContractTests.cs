namespace AIWordPressManager.Tests;

public sealed class AICenterNoFalseReadinessContractTests
{
    [Fact]
    public void AI_center_does_not_claim_runtime_readiness_without_evidence()
    {
        var root = FindRepositoryRoot();
        var pagePath = Path.Combine(
            root.FullName,
            "src",
            "AIWordPressManager.Web",
            "Components",
            "Pages",
            "AICenter.razor");

        var page = File.ReadAllText(pagePath);

        Assert.DoesNotContain("L.IsArabic ? \"جاهز\" : \"Ready\"", page, StringComparison.Ordinal);
        Assert.DoesNotContain("_working ? \"warning\" : \"success\"", page, StringComparison.Ordinal);
        Assert.Contains("L.IsArabic ? \"خامل\" : \"Idle\"", page, StringComparison.Ordinal);
        Assert.Contains("_working ? \"warning\" : \"info\"", page, StringComparison.Ordinal);

        // Keep the real runtime boundaries intact while removing the fabricated readiness claim.
        Assert.Contains("Orchestrator.ExecuteAsync", page, StringComparison.Ordinal);
        Assert.Contains("ApprovalService.Submit", page, StringComparison.Ordinal);
        Assert.Contains("SiteService.GetSitesAsync", page, StringComparison.Ordinal);
    }

    private static DirectoryInfo FindRepositoryRoot()
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "AIWordPressManager.Web.sln")))
                return current;
            current = current.Parent;
        }

        throw new DirectoryNotFoundException("Could not locate AIMWWeb repository root from the test output directory.");
    }
}
