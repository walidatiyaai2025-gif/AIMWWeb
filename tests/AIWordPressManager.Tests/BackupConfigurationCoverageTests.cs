using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class BackupConfigurationCoverageTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), $"aiwm-backup-config-tests-{Guid.NewGuid():N}");

    [Fact]
    public void DefaultApplicationRoot_UsesStableDevelopmentLayout()
    {
        var local = Path.Combine(_root, "local-app-data");

        var development = BackupManagementService.GetDefaultApplicationRoot("Development", local);
        var production = BackupManagementService.GetDefaultApplicationRoot("Production", local);

        development.Should().Be(Path.Combine(Path.GetFullPath(local), "AIWordPressManager", "Development"));
        production.Should().Be(Path.Combine(Path.GetFullPath(local), "AIWordPressManager"));
    }

    [Fact]
    public void CreateBackup_IncludesConfigurationButNeverRawSecurityKey()
    {
        var service = CreateService();
        File.WriteAllBytes(Path.Combine(service.DataDirectory, "application.db"), [1, 3, 5, 7]);
        File.WriteAllText(
            Path.Combine(service.ConfigurationDirectory, "setup.database.json"),
            "{\"Database\":{\"Provider\":\"SQLite\",\"SetupComplete\":true}}");

        var securityDirectory = Path.Combine(service.ApplicationRoot, "Security");
        Directory.CreateDirectory(securityDirectory);
        File.WriteAllText(Path.Combine(securityDirectory, ".secret-key"), "do-not-archive-raw");

        var backup = service.CreateBackup();
        var inspection = service.Inspect(backup.FileName);
        var readiness = service.CheckRestoreReadiness(backup.FileName);

        inspection.IsValid.Should().BeTrue();
        inspection.Files.Should().Contain(x =>
            x.RelativePath == "Config/setup.database.json" &&
            x.Kind == nameof(BackupContentKind.Configuration));
        inspection.Files.Should().NotContain(x =>
            (x.RelativePath ?? string.Empty).Contains("secret-key", StringComparison.OrdinalIgnoreCase));
        readiness.IsReady.Should().BeFalse();
        readiness.Checks.Should().Contain(x =>
            x.Name == "Protected secret recovery" &&
            !x.Passed &&
            x.Message.Contains("not stored", StringComparison.OrdinalIgnoreCase));
    }

    [Fact]
    public void CreateBackup_FailsClosedForExternalProviderWithoutProviderNativeDatabaseBackup()
    {
        var service = CreateService();
        File.WriteAllText(
            Path.Combine(service.ConfigurationDirectory, "setup.database.json"),
            "{\"Database\":{\"Provider\":\"SqlServer\",\"SetupComplete\":true,\"ProtectedConnectionString\":\"aesgcm:v1:ciphertext\"}}");

        var action = () => service.CreateBackup();

        action.Should().Throw<InvalidOperationException>()
            .WithMessage("*SqlServer*provider-native backup path*");
        Directory.EnumerateFiles(service.BackupDirectory, "AIWM-Backup-*.zip").Should().BeEmpty();
    }

    [Fact]
    public void RestoreReadiness_RequiresConfigurationCoverageForLegacyDataOnlyArchive()
    {
        var service = CreateService();
        File.WriteAllBytes(Path.Combine(service.DataDirectory, "application.db"), [2, 4, 6, 8]);
        var backup = service.CreateBackup();
        var readiness = service.CheckRestoreReadiness(backup.FileName);

        readiness.Checks.Should().Contain(x => x.Name == "Configuration state" && !x.Passed);
        readiness.IsReady.Should().BeFalse();
    }

    private BackupManagementService CreateService()
    {
        Directory.CreateDirectory(_root);
        return new BackupManagementService(_root);
    }

    public void Dispose()
    {
        try
        {
            if (Directory.Exists(_root)) Directory.Delete(_root, recursive: true);
        }
        catch
        {
            // Best-effort cleanup only.
        }
    }
}
