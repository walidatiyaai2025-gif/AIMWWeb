using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class GlobalShellTruthfulnessContractTests
{
    [Fact]
    public void Main_shell_does_not_fabricate_global_runtime_health()
    {
        var layout = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Layout/MainLayout.razor");

        layout.Should().NotContain("LocalSystemRunning");
        layout.Should().NotContain("<span class=\"status-dot\"");
        layout.Should().Contain("href=\"#main-content\"");
        layout.Should().Contain("@onclick=\"ToggleSidebarAsync\"");
        layout.Should().Contain("@onclick=\"OpenRecentPagesAsync\"");
        layout.Should().Contain("@onclick=\"OpenCommandPalette\"");
        layout.Should().Contain("Navigation.NavigateTo(item.Path)");
        layout.Should().Contain("ApplicationNavigationPermissionCatalog.CanAccess(_principal, item.Path)");
    }

    [Fact]
    public void Account_chip_reports_authenticated_session_state_instead_of_presence()
    {
        var chip = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Layout/CurrentUserChip.razor");

        chip.Should().Contain("_isAuthenticated = principal.Identity?.IsAuthenticated == true;");
        chip.Should().Contain("Authenticated application session");
        chip.Should().Contain("Signed in");
        chip.Should().NotContain("\"Online\"");
        chip.Should().NotContain("\"متصل\"");
        chip.Should().NotContain("/development-status.html");
        chip.Should().Contain("href=\"/account/profile\"");
        chip.Should().Contain("href=\"/settings\"");
        chip.Should().Contain("href=\"/about-build\"");
        chip.Should().Contain("href=\"/system-health\"");
        chip.Should().Contain("action=\"/logout\"");
        chip.Should().Contain("SitesService.GetSitesAsync()");
    }

    [Fact]
    public void Quick_actions_name_real_destinations_and_hide_permission_denied_shortcuts()
    {
        var quickActions = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Layout/QuickActions.razor");
        var postsExplorer = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/GlobalPostsExplorer.razor");
        var permissionCatalog = ReadRepositoryFile("src/AIWordPressManager.Web/Services/ApplicationNavigationPermissionCatalog.cs");

        quickActions.Should().Contain("\"Posts explorer\"");
        quickActions.Should().Contain("\"Open the synchronized posts explorer\"");
        quickActions.Should().Contain("\"/module/posts\"");
        quickActions.Should().NotContain("\"Create content\"");
        quickActions.Should().NotContain("start publishing work");
        quickActions.Should().Contain("@foreach (var action in VisibleActions)");
        quickActions.Should().Contain("Actions.Where(action => ApplicationNavigationPermissionCatalog.CanAccess(_principal, action.Path))");
        quickActions.Should().Contain("if (!ApplicationNavigationPermissionCatalog.CanAccess(_principal, action.Path))");
        quickActions.Should().Contain("Navigation.NavigateTo(action.Path)");
        postsExplorer.Should().Contain("@page \"/module/posts\"");
        postsExplorer.Should().Contain("GlobalPostsExplorerService");
        permissionCatalog.Should().Contain("[\"/sites/connect\"] = ApplicationPermissionCatalog.SitesManage");
        permissionCatalog.Should().Contain("[\"/module/posts\"] = ApplicationPermissionCatalog.ContentView");
        permissionCatalog.Should().Contain("[\"/module/execution\"] = ApplicationPermissionCatalog.OperationsView");
    }

    [Fact]
    public void Build_report_copy_reports_success_only_after_browser_clipboard_completion_and_exposes_retry()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AboutBuild.razor");

        var clipboardCall = page.IndexOf("await JS.InvokeVoidAsync(\"navigator.clipboard.writeText\"", StringComparison.Ordinal);
        var successAssignment = page.IndexOf("_copySucceeded = true;", StringComparison.Ordinal);

        clipboardCall.Should().BeGreaterThanOrEqualTo(0);
        successAssignment.Should().BeGreaterThan(clipboardCall);
        page.Should().Contain("catch (Exception)");
        page.Should().Contain("_copySucceeded = false;");
        page.Should().Contain("_copyError = null;");
        page.Should().Contain("No copy success was reported");
        page.Should().Contain("<AppStateBanner Kind=\"error\"");
        page.Should().Contain("RetryRequested=\"CopyBuildReportAsync\"");
        page.Should().Contain("Busy=\"@_copying\"");
        page.Should().Contain("Disabled=\"@_copying\"");
        page.Should().Contain("<AppStateBanner Kind=\"success\"");
    }

    private static string ReadRepositoryFile(string relativePath)
    {
        var directory = new DirectoryInfo(Directory.GetCurrentDirectory());
        while (directory is not null)
        {
            var candidate = Path.Combine(directory.FullName, relativePath.Replace('/', Path.DirectorySeparatorChar));
            if (File.Exists(candidate))
                return File.ReadAllText(candidate);

            directory = directory.Parent;
        }

        throw new FileNotFoundException($"Could not locate repository file '{relativePath}' from '{Directory.GetCurrentDirectory()}'.");
    }
}
