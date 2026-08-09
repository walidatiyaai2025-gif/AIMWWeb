using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Initialization;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging.Abstractions;

namespace AIWordPressManager.Tests;

[Collection("Workflow persistence")]
public sealed class DatabaseInitializationRegressionTests
{
    [Fact]
    public async Task InitializeAsync_Migrates_Fresh_Sqlite_And_Adds_Site_Owner_Compatibility()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
        await connection.OpenAsync();

        var options = new DbContextOptionsBuilder<AppDbContext>()
            .UseSqlite(connection)
            .Options;

        await using var context = new AppDbContext(options);
        var service = new DatabaseInitializationService(
            context,
            new FixedClock(DateTime.UtcNow),
            NullLogger<DatabaseInitializationService>.Instance);

        var initialize = async () => await service.InitializeAsync();
        await initialize.Should().NotThrowAsync();

        var migrations = await context.Database.GetMigrationsAsync();
        migrations.Should().Contain("20260809094500_AddSiteSyncRuns");

        await using (var command = connection.CreateCommand())
        {
            command.CommandText = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='SiteSyncRuns';";
            var tableCount = Convert.ToInt32(await command.ExecuteScalarAsync());
            tableCount.Should().Be(1, "the sync-history migration must create SiteSyncRuns before synchronization can persist a run");
        }

        var siteColumns = new List<string>();
        await using (var command = connection.CreateCommand())
        {
            command.CommandText = "PRAGMA table_info('Sites');";
            await using var reader = await command.ExecuteReaderAsync();
            while (await reader.ReadAsync())
                siteColumns.Add(reader.GetString(1));
        }

        siteColumns.Should().Contain("OwnerUserId");

        var siteIndexes = new List<string>();
        await using (var command = connection.CreateCommand())
        {
            command.CommandText = "PRAGMA index_list('Sites');";
            await using var reader = await command.ExecuteReaderAsync();
            while (await reader.ReadAsync())
                siteIndexes.Add(reader.GetString(1));
        }

        siteIndexes.Should().Contain("IX_Sites_OwnerUserId");
        siteIndexes.Should().Contain("IX_Sites_OwnerUserId_SiteUrl");
        siteIndexes.Should().NotContain("IX_Sites_SiteUrl");
        (await context.Database.GetPendingMigrationsAsync()).Should().BeEmpty();
    }

    private sealed class FixedClock(DateTime utcNow) : IClock
    {
        public DateTime UtcNow { get; } = utcNow;
    }
}
