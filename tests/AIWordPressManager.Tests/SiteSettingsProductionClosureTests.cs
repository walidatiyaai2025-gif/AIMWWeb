using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class SiteSettingsProductionClosureTests
{
    [Fact]
    public void Site_settings_route_requires_sites_view()
    {
        var catalog = ReadRepositoryFile("src/AIWordPressManager.Web/Services/ApplicationRoutePermissionCatalog.cs");

        catalog.Should().Contain("[\"SiteSettings\"] = ApplicationPermissionCatalog.SitesView");
    }

    [Fact]
    public void Site_settings_read_uses_one_permission_checked_owner_scoped_snapshot()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/SiteSettings.razor");
        var service = ReadRepositoryFile("src/AIWordPressManager.Web/Services/SiteWebService.cs");

        page.Should().Contain("SiteService.GetSiteSettingsAsync(Id)");
        page.Should().NotContain("SiteService.GetSiteAsync(Id)");
        page.Should().NotContain("SiteService.GetCredentialSummaryAsync(Id)");

        var methodStart = service.IndexOf("public async Task<SiteSettingsSnapshot?> GetSiteSettingsAsync", StringComparison.Ordinal);
        var permission = service.IndexOf("currentUser.RequirePermission(ApplicationPermissionCatalog.SitesView);", methodStart, StringComparison.Ordinal);
        var ownerQuery = service.IndexOf("x.Id == siteId && x.OwnerUserId == OwnerId", methodStart, StringComparison.Ordinal);

        methodStart.Should().BeGreaterThanOrEqualTo(0);
        permission.Should().BeGreaterThan(methodStart);
        ownerQuery.Should().BeGreaterThan(permission);
    }

    [Fact]
    public void Credential_save_result_distinguishes_limited_permissions_from_failed_persistence()
    {
        var service = ReadRepositoryFile("src/AIWordPressManager.Web/Services/SiteWebService.cs");
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/SiteSettings.razor");

        service.Should().Contain("credentialSaved = status is SiteConnectionStatus.Connected or SiteConnectionStatus.LimitedPermissions");
        service.Should().Contain("new SiteCredentialSaveResult(credentialSaved, status, result.Message, result.Diagnostics)");
        page.Should().Contain("SaveCredentialAndTestDetailedAsync");
        page.Should().Contain("result.CredentialSaved && result.ConnectionStatus == AIWordPressManager.Domain.Enums.SiteConnectionStatus.LimitedPermissions");
        page.Should().Contain("Credential saved, but WordPress reported limited permissions.");
        page.Should().Contain("Connection test failed and the new credential was not saved.");
        page.Should().NotContain("if (result.IsSuccess) Success");
    }

    [Fact]
    public void Unexpected_site_settings_failures_do_not_echo_raw_exception_messages()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/SiteSettings.razor");

        page.Should().Contain("catch { Fail(L.IsArabic ? \"تعذر تحميل إعدادات الموقع. حاول مرة أخرى.\" : \"Site settings could not be loaded. Try again.\"); }");
        page.Should().Contain("catch { Fail(L.IsArabic ? \"تعذر حفظ الاعتماد أو اختبار الاتصال. حاول مرة أخرى.\" : \"The credential could not be saved or tested. Try again.\"); }");
        page.Should().NotContain("catch (Exception ex) { Fail(ex.Message);");
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
