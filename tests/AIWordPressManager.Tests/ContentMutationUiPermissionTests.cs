using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class ContentMutationUiPermissionTests
{
    [Theory]
    [InlineData("/content")]
    [InlineData("/module/posts")]
    [InlineData("/module/pages")]
    [InlineData("/module/media")]
    [InlineData("/module/taxonomy")]
    [InlineData("/module/comments")]
    [InlineData("/module/users")]
    [InlineData("/sites/11111111-1111-1111-1111-111111111111/explorer")]
    [InlineData("/sites/11111111-1111-1111-1111-111111111111/content/post/42/edit")]
    [InlineData("/sites/11111111-1111-1111-1111-111111111111/media")]
    [InlineData("/sites/11111111-1111-1111-1111-111111111111/taxonomy")]
    [InlineData("/sites/11111111-1111-1111-1111-111111111111/comments")]
    [InlineData("/sites/11111111-1111-1111-1111-111111111111/users")]
    public void Content_mutation_surfaces_require_edit_affordance(string path)
    {
        ApplicationContentMutationUiPolicy.RequiresContentEdit(path).Should().BeTrue();
    }

    [Theory]
    [InlineData("/")]
    [InlineData("/sites")]
    [InlineData("/sites/connect")]
    [InlineData("/approvals")]
    [InlineData("/execution-center")]
    [InlineData("/ai-center")]
    [InlineData("/content-planner")]
    public void Non_content_mutation_surfaces_are_not_implicitly_disabled(string path)
    {
        ApplicationContentMutationUiPolicy.RequiresContentEdit(path).Should().BeFalse();
    }

    [Fact]
    public void Shared_app_button_enforces_ContentEdit_for_event_actions_on_content_surfaces()
    {
        var source = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppButton.razor");

        source.Should().Contain("ApplicationContentMutationUiPolicy.RequiresContentEdit");
        source.Should().Contain("OnClick.HasDelegate");
        source.Should().Contain("ApplicationPermissionCatalog.ContentEdit");
        source.Should().Contain("disabled=\"@EffectiveDisabled\"");
        source.Should().Contain("href=\"@EffectiveHref\"");
    }

    [Fact]
    public void Content_editor_disables_native_mutation_controls_and_guards_approval_submission()
    {
        var source = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor");

        source.Should().Contain("private bool CanEdit => CurrentUser.HasPermission(ApplicationPermissionCatalog.ContentEdit)");
        source.Should().Contain("disabled=\"@(!CanEdit || _saving || _submittingApproval)\"");
        source.Should().Contain("CurrentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit)");
        source.Should().Contain("View-only mode");
    }

    private static string ReadRepositoryFile(string relativePath)
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "AIWordPressManager.Web.sln")))
                return File.ReadAllText(Path.Combine(current.FullName, relativePath));

            current = current.Parent;
        }

        throw new DirectoryNotFoundException("Could not locate repository root from test output.");
    }
}
