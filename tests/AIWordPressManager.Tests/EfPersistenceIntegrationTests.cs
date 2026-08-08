using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Sites;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Sites;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

[Collection("Workflow persistence")]
public sealed class EfPersistenceIntegrationTests
{
    [Fact]
    public async Task EnsureCreated_Creates_Core_Tables()
    {
        await using var fixture = await SqliteFixture.CreateAsync();
        var tables = new List<string>();
        await using var command = fixture.Connection.CreateCommand();
        command.CommandText = "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name;";
        await using var reader = await command.ExecuteReaderAsync();
        while (await reader.ReadAsync()) tables.Add(reader.GetString(0));

        tables.Should().Contain(new[]
        {
            "Sites", "SiteCredentials", "WordPressContentRecords",
            "WordPressCategoryRecords", "WordPressTagRecords",
            "WordPressMediaRecords", "ExecutionJobs"
        });
    }

    [Fact]
    public async Task Site_And_Credential_RoundTrip_Through_Sqlite()
    {
        await using var fixture = await SqliteFixture.CreateAsync();
        var now = DateTime.UtcNow;
        var site = new Site("Integration Site", new Uri("https://example.test"), now);
        fixture.Context.Sites.Add(site);
        fixture.Context.SiteCredentials.Add(new SiteCredential(site.Id, "editor", "stored-value", now));
        await fixture.Context.SaveChangesAsync();
        fixture.Context.ChangeTracker.Clear();

        var loadedSite = await fixture.Context.Sites.SingleAsync();
        var loadedCredential = await fixture.Context.SiteCredentials.SingleAsync();
        loadedSite.Name.Should().Be("Integration Site");
        loadedCredential.SiteId.Should().Be(loadedSite.Id);
        loadedCredential.UserName.Should().Be("editor");
    }

    [Fact]
    public async Task Credential_Requires_An_Existing_Site()
    {
        await using var fixture = await SqliteFixture.CreateAsync();
        fixture.Context.SiteCredentials.Add(new SiteCredential(Guid.NewGuid(), "editor", "stored-value", DateTime.UtcNow));

        var action = async () => await fixture.Context.SaveChangesAsync();
        await action.Should().ThrowAsync<DbUpdateException>();
    }

    [Fact]
    public async Task Model_Contains_Required_Credential_Foreign_Key()
    {
        await using var fixture = await SqliteFixture.CreateAsync();
        var entity = fixture.Context.Model.FindEntityType(typeof(SiteCredential));
        var foreignKey = entity!.GetForeignKeys().Single(x => x.PrincipalEntityType.ClrType == typeof(Site));

        foreignKey.Properties.Should().ContainSingle(x => x.Name == nameof(SiteCredential.SiteId));
        foreignKey.IsRequired.Should().BeTrue();
    }

    [Fact]
    public async Task Registering_The_Same_Url_Creates_Independent_Site_Profiles()
    {
        await using var fixture = await SqliteFixture.CreateAsync();
        var now = DateTime.UtcNow;
        var service = new SiteManagementService(
            fixture.Context,
            new PassThroughSecretProtectionService(),
            new FixedClock(now));

        var first = await service.CreateAsync(new CreateSiteRequest(
            "Primary Profile",
            "https://same.example/",
            "primary-user",
            "primary-password",
            null,
            null,
            null));
        var second = await service.CreateAsync(new CreateSiteRequest(
            "Secondary Profile",
            "https://same.example",
            "secondary-user",
            "secondary-password",
            null,
            null,
            null));

        first.IsSuccess.Should().BeTrue();
        second.IsSuccess.Should().BeTrue();
        first.Value.Should().NotBe(second.Value);

        var profiles = await fixture.Context.Sites
            .IgnoreQueryFilters()
            .Where(x => x.SiteUrl == "https://same.example")
            .OrderBy(x => x.Name)
            .ToListAsync();

        profiles.Should().HaveCount(2);
        profiles.Select(x => x.Id).Should().Contain(new[] { first.Value, second.Value });
        profiles.Select(x => x.Name).Should().Contain(new[] { "Primary Profile", "Secondary Profile" });

        var credentials = await fixture.Context.SiteCredentials
            .Where(x => profiles.Select(p => p.Id).Contains(x.SiteId))
            .OrderBy(x => x.UserName)
            .ToListAsync();

        credentials.Should().HaveCount(2);
        credentials.Select(x => x.UserName).Should().Contain(new[] { "primary-user", "secondary-user" });
        credentials.Select(x => x.ProtectedApplicationPassword).Should().Contain(new[] { "primary-password", "secondary-password" });
    }

    [Fact]
    public async Task Registering_A_Url_After_Soft_Delete_Creates_A_New_Profile()
    {
        await using var fixture = await SqliteFixture.CreateAsync();
        var now = DateTime.UtcNow;
        var service = new SiteManagementService(
            fixture.Context,
            new PassThroughSecretProtectionService(),
            new FixedClock(now));

        var original = await service.CreateAsync(new CreateSiteRequest(
            "Old Site",
            "https://deleted.example",
            "old-user",
            "old-password",
            null,
            null,
            null));
        original.IsSuccess.Should().BeTrue();

        var delete = await service.DeleteAsync(original.Value);
        delete.IsSuccess.Should().BeTrue();
        fixture.Context.ChangeTracker.Clear();

        var replacement = await service.CreateAsync(new CreateSiteRequest(
            "Replacement Site",
            "https://deleted.example/",
            "new-user",
            "new-password",
            null,
            null,
            null));

        replacement.IsSuccess.Should().BeTrue();
        replacement.Value.Should().NotBe(original.Value);

        var profiles = await fixture.Context.Sites
            .IgnoreQueryFilters()
            .Where(x => x.SiteUrl == "https://deleted.example")
            .OrderBy(x => x.Name)
            .ToListAsync();

        profiles.Should().HaveCount(2);
        profiles.Single(x => x.Id == original.Value).IsDeleted.Should().BeTrue();
        profiles.Single(x => x.Id == replacement.Value).IsDeleted.Should().BeFalse();

        var credential = await fixture.Context.SiteCredentials.SingleAsync(x => x.SiteId == replacement.Value);
        credential.UserName.Should().Be("new-user");
        credential.ProtectedApplicationPassword.Should().Be("new-password");
    }

    private sealed class PassThroughSecretProtectionService : ISecretProtectionService
    {
        public Task<string> ProtectAsync(string plainText, CancellationToken cancellationToken = default)
            => Task.FromResult(plainText);

        public Task<string> UnprotectAsync(string protectedValue, CancellationToken cancellationToken = default)
            => Task.FromResult(protectedValue);
    }

    private sealed class FixedClock(DateTime utcNow) : IClock
    {
        public DateTime UtcNow { get; } = utcNow;
    }

    private sealed class SqliteFixture : IAsyncDisposable
    {
        private SqliteFixture(SqliteConnection connection, AppDbContext context)
        {
            Connection = connection;
            Context = context;
        }

        public SqliteConnection Connection { get; }
        public AppDbContext Context { get; }

        public static async Task<SqliteFixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
            var context = new AppDbContext(options);
            await context.Database.EnsureCreatedAsync();
            return new SqliteFixture(connection, context);
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}
