using Xunit;

namespace AIWordPressManager.Tests;

public sealed class SettingsDatabaseProviderHonestyContractTests
{
    [Fact]
    public void Settings_reads_database_provider_and_setup_state_from_runtime_configuration()
    {
        var root = FindRepositoryRoot();
        var path = Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Components", "Pages", "Settings.razor");
        var source = File.ReadAllText(path);

        Assert.Contains("@inject IConfiguration Config", source, StringComparison.Ordinal);
        Assert.Contains("Config[\"Database:Provider\"]", source, StringComparison.Ordinal);
        Assert.Contains("Config.GetValue<bool>(\"Database:SetupComplete\")", source, StringComparison.Ordinal);
        Assert.Contains("@DatabaseProviderText", source, StringComparison.Ordinal);
        Assert.Contains("@DatabaseConfigurationState", source, StringComparison.Ordinal);
        Assert.DoesNotContain("<strong>SQLite</strong>", source, StringComparison.Ordinal);
        Assert.DoesNotContain("<span class=\"settings-badge success\">SQLite</span>", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Local and portable", source, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void Database_setup_service_and_Settings_share_the_authoritative_provider_keys()
    {
        var root = FindRepositoryRoot();
        var settingsPath = Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Components", "Pages", "Settings.razor");
        var setupPath = Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Services", "DatabaseSetupService.cs");
        var settings = File.ReadAllText(settingsPath);
        var setup = File.ReadAllText(setupPath);

        foreach (var provider in new[] { "SQLite", "SqlServer", "PostgreSQL", "MySQL", "MariaDB" })
        {
            Assert.Contains($"\"{provider}\"", setup, StringComparison.Ordinal);
        }

        Assert.Contains("Database:SetupComplete", settings, StringComparison.Ordinal);
        Assert.Contains("Database:Provider", settings, StringComparison.Ordinal);
        Assert.Contains("Database:SetupComplete", setup, StringComparison.Ordinal);
        Assert.Contains("Provider", setup, StringComparison.Ordinal);
    }

    private static DirectoryInfo FindRepositoryRoot()
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "AIWordPressManager.Web.sln")))
            {
                return current;
            }

            current = current.Parent;
        }

        throw new DirectoryNotFoundException("Could not locate AIMWWeb repository root from the test output directory.");
    }
}
