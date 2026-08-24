using AIWordPressManager.Web.Services;
using Xunit;

namespace AIWordPressManager.Tests;

public sealed class OperationalDiagnosticsAuthorizationContractTests
{
    [Fact]
    public void Live_log_diagnostics_require_settings_manage_before_reading_server_logs()
    {
        var root = FindRepositoryRoot();
        var pagePath = Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Components", "Pages", "LogsAndErrors.razor");
        var page = File.ReadAllText(pagePath);

        Assert.Contains("@page \"/logs\"", page, StringComparison.Ordinal);
        Assert.Contains("@page \"/module/logs\"", page, StringComparison.Ordinal);
        Assert.Contains("Authorize(Policy = ApplicationPermissionCatalog.SettingsManage)", page, StringComparison.Ordinal);
        Assert.Equal(ApplicationPermissionCatalog.SettingsManage, ApplicationRoutePermissionCatalog.All["LogsAndErrors"]);
        Assert.Contains("LogReaderService _reader = new()", page, StringComparison.Ordinal);
        Assert.Contains("_reader.GetFiles()", page, StringComparison.Ordinal);
        Assert.Contains("_reader.Read(_selectedPath, _take)", page, StringComparison.Ordinal);
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
