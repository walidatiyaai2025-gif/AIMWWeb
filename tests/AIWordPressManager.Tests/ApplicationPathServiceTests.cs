using AIWordPressManager.Infrastructure.Paths;
using FluentAssertions;
using Microsoft.Extensions.Configuration;

namespace AIWordPressManager.Tests;

public sealed class ApplicationPathServiceTests
{
    [Fact]
    public void Development_UsesStableLocalApplicationData_NotBuildOutput()
    {
        var configuration = CreateConfiguration(new Dictionary<string, string?>
        {
            ["ASPNETCORE_ENVIRONMENT"] = "Development",
            ["Application:PortableMode"] = "false"
        });

        var service = new ApplicationPathService(configuration);

        var dataDirectory = Path.GetFullPath(service.GetApplicationDataDirectory());
        var expectedRoot = Path.GetFullPath(Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "AIWordPressManager",
            "Development"));

        dataDirectory.Should().StartWith(expectedRoot, StringComparison.OrdinalIgnoreCase);
        dataDirectory.Should().NotStartWith(Path.GetFullPath(AppContext.BaseDirectory), StringComparison.OrdinalIgnoreCase);
        service.GetDatabasePath().Should().EndWith("AIWordPressManager.Development.db");
    }

    [Fact]
    public void AspNetCoreEnvironment_IsRecognized_WhenDotNetEnvironmentIsNotConfigured()
    {
        var configuration = CreateConfiguration(new Dictionary<string, string?>
        {
            ["ASPNETCORE_ENVIRONMENT"] = "Development",
            ["Application:PortableMode"] = "false"
        });

        var service = new ApplicationPathService(configuration);

        service.GetLogsDirectory().Should().Contain(Path.Combine("AIWordPressManager", "Development", "Logs"));
    }

    [Fact]
    public void PortableMode_RemainsBesideApplication_EvenInDevelopment()
    {
        var configuration = CreateConfiguration(new Dictionary<string, string?>
        {
            ["ASPNETCORE_ENVIRONMENT"] = "Development",
            ["Application:PortableMode"] = "true"
        });

        var service = new ApplicationPathService(configuration);

        service.GetApplicationDataDirectory()
            .Should().Be(Path.Combine(AppContext.BaseDirectory, "Data"));
    }

    private static IConfiguration CreateConfiguration(Dictionary<string, string?> values) =>
        new ConfigurationBuilder()
            .AddInMemoryCollection(values)
            .Build();
}
