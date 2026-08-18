using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class ApplicationSessionUiContractTests
{
    [Fact]
    public void Authentication_issues_a_server_session_claim_before_signing_in()
    {
        var authentication = ReadRepositoryFile("src/AIWordPressManager.Web/Services/LocalAuthenticationService.cs");

        authentication.Should().Contain("_sessionStore.CreateAsync(");
        authentication.Should().Contain("new(ApplicationSessionStore.ClaimType, session.SessionId)");
        authentication.Should().Contain("ApplicationSessionStore.PersistentLifetime");
    }

    [Fact]
    public void Authorized_requests_validate_server_session_and_reject_stale_cookies()
    {
        var handler = ReadRepositoryFile("src/AIWordPressManager.Web/Services/BlazorFrameworkAuthorizationResultHandler.cs");

        handler.Should().Contain("ApplicationSessionStore.ClaimType");
        handler.Should().Contain("store.ValidateAsync(sessionId, userId");
        handler.Should().Contain("context.SignOutAsync(CookieAuthenticationDefaults.AuthenticationScheme)");
        handler.Should().Contain("PolicyAuthorizationResult.Challenge()");
        handler.Should().Contain("User signed out");
    }

    [Fact]
    public void Session_records_use_existing_application_settings_not_provider_specific_schema()
    {
        var store = ReadRepositoryFile("src/AIWordPressManager.Web/Services/ApplicationSessionStore.cs");

        store.Should().Contain("Security.Session.");
        store.Should().Contain("dbContext.ApplicationSettings");
        store.Should().NotContain("ExecuteSqlRaw");
        store.Should().NotContain("Database.Migrate");
    }

    [Fact]
    public void Administrator_session_page_requires_users_manage_and_exposes_revocation()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/ApplicationSessions.razor");

        page.Should().Contain("@page \"/admin/sessions\"");
        page.Should().Contain("ApplicationPermissionCatalog.UsersManage");
        page.Should().Contain("_service.RevokeAsync(session.SessionId)");
        page.Should().Contain("L.IsArabic");
    }

    [Fact]
    public void Self_service_session_page_only_uses_own_session_operations()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/MySessions.razor");

        page.Should().Contain("@page \"/account/sessions\"");
        page.Should().Contain("_service.ListOwnAsync");
        page.Should().Contain("_service.RevokeOwnAsync(session.SessionId)");
        page.Should().Contain("_service.RevokeOtherOwnSessionsAsync");
        page.Should().NotContain("ListAllAsync");
    }

    [Fact]
    public void Account_security_changes_revoke_server_sessions()
    {
        var users = ReadRepositoryFile("src/AIWordPressManager.Web/Services/ApplicationUserAdministrationService.cs");
        var roles = ReadRepositoryFile("src/AIWordPressManager.Web/Services/ApplicationRoleAdministrationService.cs");

        users.Should().Contain("Account identity or role changed");
        users.Should().Contain("Account disabled");
        users.Should().Contain("Password reset");
        roles.Should().Contain("Role permissions changed");
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
