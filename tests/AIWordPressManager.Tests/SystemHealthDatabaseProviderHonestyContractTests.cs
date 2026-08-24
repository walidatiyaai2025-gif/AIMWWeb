using Xunit;

namespace AIWordPressManager.Tests;

public sealed class SystemHealthDatabaseProviderHonestyContractTests
{
    [Fact]
    public void System_health_uses_the_configured_database_provider_instead_of_a_parallel_sqlite_file()
    {
        var root = FindRepositoryRoot();
        var servicePath = Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Services", "SystemHealthWebService.cs");
        var pagePath = Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Components", "Pages", "SystemHealth.razor");
        var service = File.ReadAllText(servicePath);
        var page = File.ReadAllText(pagePath);

        Assert.Contains("configuration[\"Database:Provider\"]", service, StringComparison.Ordinal);
        Assert.Contains("configuration.GetValue<bool>(\"Database:SetupComplete\")", service, StringComparison.Ordinal);
        Assert.Contains("dbContext.Database.CanConnectAsync", service, StringComparison.Ordinal);
        Assert.Contains("PRAGMA quick_check;", service, StringComparison.Ordinal);
        Assert.Contains("new(\"database\"", service, StringComparison.Ordinal);
        Assert.DoesNotContain("paths.GetDatabasePath()", service, StringComparison.Ordinal);
        Assert.DoesNotContain("new SqliteConnectionStringBuilder", service, StringComparison.Ordinal);

        Assert.Contains("configured database", page, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("\"database\" =>", page, StringComparison.Ordinal);
        Assert.Contains("check.Target", page, StringComparison.Ordinal);
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
