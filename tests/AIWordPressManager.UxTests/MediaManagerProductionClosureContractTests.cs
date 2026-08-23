using FluentAssertions;
using Xunit;

namespace AIWordPressManager.UxTests;

public sealed class MediaManagerProductionClosureContractTests
{
    [Fact]
    public void Media_manager_exposes_only_real_mutation_paths_and_keeps_upload_on_canonical_panel()
    {
        var source = File.ReadAllText(FindRepositoryFile("src/AIWordPressManager.Web/Components/Pages/MediaManager.razor"));

        source.Should().Contain("<MediaBatchUploadPanel SiteId=\"SiteId\" OnBatchUploaded=\"RefreshLocalAsync\" />",
            "media upload must stay on the canonical production upload component instead of a duplicate prototype UI");
        source.Should().Contain("MediaService.UpdateMetadataAsync", "visible metadata save must reach the WordPress media service");
        source.Should().Contain("MediaService.DeleteAsync", "visible permanent delete must reach the WordPress media service");
        source.Should().Contain("SyncService.SynchronizeAsync", "mutations and refresh must reconcile through the production sync service");
        source.Should().Contain("CurrentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit)",
            "media mutations must retain the Content.Edit authorization boundary");

        source.Should().NotContain("href=\"#\"", "media actions must not regress to placeholder navigation");
        source.Should().NotContain("javascript:", "media actions must not regress to javascript placeholders");
        source.Should().NotContain("NotImplementedException", "visible media capability must be real or explicitly unavailable");
    }

    [Fact]
    public void Media_manager_does_not_reintroduce_the_removed_inline_upload_prototype()
    {
        var source = File.ReadAllText(FindRepositoryFile("src/AIWordPressManager.Web/Components/Pages/MediaManager.razor"));

        source.Should().NotContain("<InputFile", "MediaManager must delegate visible upload UI to MediaBatchUploadPanel");
        source.Should().NotContain("UploadClickedAsync", "the obsolete inline upload click path must not return");
        source.Should().NotContain("OnFileSelected", "the obsolete inline file-selection handler must not return");
        source.Should().NotContain("SelectedFileIsValid", "the obsolete inline upload validation state must not return");
    }

    private static string FindRepositoryFile(string relativePath)
    {
        var directory = new DirectoryInfo(AppContext.BaseDirectory);
        while (directory is not null)
        {
            var candidate = Path.Combine(directory.FullName, relativePath.Replace('/', Path.DirectorySeparatorChar));
            if (File.Exists(candidate)) return candidate;
            directory = directory.Parent;
        }

        throw new FileNotFoundException($"Could not locate repository file '{relativePath}' from '{AppContext.BaseDirectory}'.");
    }
}
