namespace AIWordPressManager.Tests;

public sealed class ContentEditorSeoHonestyContractTests
{
    [Fact]
    public void Content_editor_does_not_present_local_editorial_checks_as_an_SEO_score()
    {
        var root = FindRepositoryRoot();
        var pagePath = Path.Combine(
            root.FullName,
            "src",
            "AIWordPressManager.Web",
            "Components",
            "Pages",
            "ContentEditor.razor");

        var page = File.ReadAllText(pagePath);

        Assert.DoesNotContain("SeoScore", page, StringComparison.Ordinal);
        Assert.DoesNotContain("seo-score-chip", page, StringComparison.Ordinal);
        Assert.DoesNotContain("@SeoScore/100", page, StringComparison.Ordinal);
        Assert.Contains("This is not an SEO audit score; use the SEO workspace for real analysis.", page, StringComparison.Ordinal);
        Assert.Contains("@($\"/sites/{SiteId}/seo\")", page, StringComparison.Ordinal);

        // Removing the misleading score must not sever the real content mutation and approval paths.
        Assert.Contains("CurrentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit)", page, StringComparison.Ordinal);
        Assert.Contains("EditorService.UpdateAsync", page, StringComparison.Ordinal);
        Assert.Contains("SyncService.SynchronizeAsync", page, StringComparison.Ordinal);
        Assert.Contains("ApprovalService.Submit", page, StringComparison.Ordinal);
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
