using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class PublicLandingRenderModeContractTests
{
    [Fact]
    public void App_keeps_the_public_landing_static_while_the_application_remains_interactive()
    {
        var app = ReadRepositoryFile("src/AIWordPressManager.Web/Components/App.razor");

        app.Should().Contain("<HeadOutlet @rendermode=\"@PageRenderMode\" />");
        app.Should().Contain("<Routes @rendermode=\"@PageRenderMode\" />");
        app.Should().Contain("HttpContext.Request.Path.StartsWithSegments(PublicEntryRouting.LandingPath)");
        app.Should().Contain("? null");
        app.Should().Contain(": InteractiveServer;");
    }

    [Fact]
    public void Public_landing_does_not_override_static_ssr_with_an_interactive_page_render_mode()
    {
        var welcome = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/Welcome.razor");

        welcome.Should().NotContain("@rendermode InteractiveServer",
            "the anonymous landing page must not start a protected Blazor Server circuit");
        welcome.Should().Contain("Request.Cookies[\"aiwp-language\"]",
            "static SSR must still render the persisted Arabic/English preference on the server");
    }

    [Fact]
    public void Public_landing_language_toggle_persists_for_static_ssr()
    {
        var language = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/js/app-language.js");
        var bootstrap = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/js/language-bootstrap.js");

        language.Should().Contain("document.cookie = `${appLanguageCookie}=${culture};path=/;SameSite=Lax`");
        language.Should().Contain("toggleAndReload");
        bootstrap.Should().Contain("const cookieName = 'aiwp-language'");
        bootstrap.Should().Contain("window.location.pathname === '/welcome'");
    }

    [Fact]
    public void Public_landing_remains_anonymous_without_relaxing_the_global_component_authorization_boundary()
    {
        var welcome = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/Welcome.razor");
        var program = ReadRepositoryFile("src/AIWordPressManager.Web/Program.cs");

        welcome.Should().Contain("@attribute [Microsoft.AspNetCore.Authorization.AllowAnonymous]");
        program.Should().Contain("app.MapRazorComponents<App>()");
        program.Should().Contain(".RequireAuthorization();");
    }

    private static string ReadRepositoryFile(string relativePath)
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            var solution = Path.Combine(current.FullName, "AIWordPressManager.Web.sln");
            if (File.Exists(solution)) return File.ReadAllText(Path.Combine(current.FullName, relativePath));
            current = current.Parent;
        }

        throw new DirectoryNotFoundException("Could not locate repository root from test output.");
    }
}
