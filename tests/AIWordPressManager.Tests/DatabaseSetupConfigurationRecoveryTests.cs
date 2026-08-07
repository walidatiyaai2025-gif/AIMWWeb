using System.Text.Json;
using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class DatabaseSetupConfigurationRecoveryTests
{
    [Fact]
    public void InvalidJson_IsPreserved_AndReplacedWithIncompleteSetup()
    {
        var root = CreateTemporaryDirectory();
        try
        {
            var configPath = Path.Combine(root, "setup.database.json");
            File.WriteAllText(configPath, "{ invalid json");

            var message = DatabaseSetupService.ValidateExistingConfigurationFile(configPath);

            message.Should().Contain("Invalid database setup JSON");
            File.Exists(configPath).Should().BeTrue();
            Directory.GetFiles(root, "setup.database.invalid-*.json").Should().ContainSingle();

            using var document = JsonDocument.Parse(File.ReadAllText(configPath));
            document.RootElement.GetProperty("Database").GetProperty("SetupComplete").GetBoolean().Should().BeFalse();
        }
        finally
        {
            DeleteTemporaryDirectory(root);
        }
    }

    [Fact]
    public void MissingSqliteFile_MarksSetupIncomplete_WithoutCreatingDatabase()
    {
        var root = CreateTemporaryDirectory();
        try
        {
            var databasePath = Path.Combine(root, "missing.db");
            var configPath = Path.Combine(root, "setup.database.json");
            var connectionString = $"Data Source={databasePath};Foreign Keys=True;Pooling=True";
            File.WriteAllText(configPath, $$"""
            {
              "Database": {
                "SetupComplete": true,
                "Provider": "SQLite",
                "ConnectionString": "{{connectionString.Replace("\\", "\\\\")}}"
              }
            }
            """);

            var message = DatabaseSetupService.ValidateExistingConfigurationFile(configPath);

            message.Should().Contain("was not found");
            File.Exists(databasePath).Should().BeFalse();

            using var document = JsonDocument.Parse(File.ReadAllText(configPath));
            var database = document.RootElement.GetProperty("Database");
            database.GetProperty("SetupComplete").GetBoolean().Should().BeFalse();
            database.GetProperty("ConnectionString").GetString().Should().Be(connectionString);
        }
        finally
        {
            DeleteTemporaryDirectory(root);
        }
    }

    [Fact]
    public void ExistingSqliteFile_LeavesCompletedSetupUntouched()
    {
        var root = CreateTemporaryDirectory();
        try
        {
            var databasePath = Path.Combine(root, "existing.db");
            File.WriteAllBytes(databasePath, []);
            var configPath = Path.Combine(root, "setup.database.json");
            var connectionString = $"Data Source={databasePath};Foreign Keys=True;Pooling=True";
            File.WriteAllText(configPath, $$"""
            {
              "Database": {
                "SetupComplete": true,
                "Provider": "SQLite",
                "ConnectionString": "{{connectionString.Replace("\\", "\\\\")}}"
              }
            }
            """);

            var message = DatabaseSetupService.ValidateExistingConfigurationFile(configPath);

            message.Should().BeNull();
            using var document = JsonDocument.Parse(File.ReadAllText(configPath));
            document.RootElement.GetProperty("Database").GetProperty("SetupComplete").GetBoolean().Should().BeTrue();
        }
        finally
        {
            DeleteTemporaryDirectory(root);
        }
    }

    private static string CreateTemporaryDirectory()
    {
        var path = Path.Combine(Path.GetTempPath(), "AIWM-Setup-Recovery-Tests", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(path);
        return path;
    }

    private static void DeleteTemporaryDirectory(string path)
    {
        try
        {
            if (Directory.Exists(path)) Directory.Delete(path, recursive: true);
        }
        catch
        {
            // Test cleanup must not hide the assertion result.
        }
    }
}
