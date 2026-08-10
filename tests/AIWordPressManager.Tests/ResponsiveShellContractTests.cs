using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class ResponsiveShellContractTests
{
    [Fact]
    public void Responsive_css_and_runtime_use_the_same_tablet_breakpoint()
    {
        var css = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/css/design-system-responsive.css");
        var themeRuntime = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/js/app-theme.js");

        css.Should().Contain("@media (max-width: 1024px)");
        themeRuntime.Should().Contain("matchMedia(\"(max-width: 1024px)\")");
    }

    [Fact]
    public void Responsive_drawer_does_not_persist_mobile_or_tablet_state_as_desktop_preference()
    {
        var themeRuntime = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/js/app-theme.js");

        themeRuntime.Should().Contain("return isResponsiveDrawer() ? true : getDesktopSidebarCollapsed()");
        themeRuntime.Should().Contain("if (!isResponsiveDrawer())");
        themeRuntime.Should().Contain("localStorage.setItem(sidebarKey");
        themeRuntime.Should().Contain("orientationchange");
    }

    [Fact]
    public void Responsive_drawer_controls_are_owned_by_the_blazor_shell()
    {
        var layout = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Layout/MainLayout.razor");
        var themeRuntime = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/js/app-theme.js");
        var css = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/css/design-system-responsive.css");

        layout.Should().Contain("responsive-sidebar-close");
        layout.Should().Contain("responsive-nav-backdrop");
        themeRuntime.Should().Contain(".sidebar a[href]");
        themeRuntime.Should().NotContain("document.createElement");
        css.Should().Contain(".responsive-nav-backdrop");
        css.Should().Contain(".responsive-sidebar-close");
    }

    [Fact]
    public void Application_viewport_and_shell_support_device_safe_areas()
    {
        var app = ReadRepositoryFile("src/AIWordPressManager.Web/Components/App.razor");
        var css = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/css/design-system-responsive.css");

        app.Should().Contain("viewport-fit=cover");
        app.Should().Contain("css/design-system-responsive.css");
        css.Should().Contain("safe-area-inset-top");
        css.Should().Contain("safe-area-inset-bottom");
        css.Should().Contain("100dvh");
    }

    [Fact]
    public void Responsive_shell_contains_overflow_and_landscape_guards()
    {
        var css = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/css/design-system-responsive.css");

        css.Should().Contain("overflow-x: clip");
        css.Should().Contain("overscroll-behavior: contain");
        css.Should().Contain("orientation: landscape");
        css.Should().Contain("max-height: 600px");
    }

    [Fact]
    public void Shared_data_grid_supports_an_explicit_mobile_card_alternative()
    {
        var grid = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor");
        var css = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/css/app-data-grid.css");

        grid.Should().Contain("RenderFragment<TItem>? MobileRowTemplate");
        grid.Should().Contain("app-data-grid__mobile-list");
        grid.Should().Contain("has-mobile-alternative");
        css.Should().Contain(".app-data-grid__viewport.has-mobile-alternative{display:none}");
        css.Should().Contain("overscroll-behavior-inline:contain");
    }

    [Fact]
    public void Shared_dialog_contract_contains_content_and_action_overflow()
    {
        var css = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/css/ui-framework-extended.css");

        css.Should().Contain("max-width:100vw");
        css.Should().Contain("overflow-x:auto");
        css.Should().Contain("overflow-wrap:anywhere");
        css.Should().Contain(".app-dialog__footer>.app-button");
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