using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
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

        var loaded = await fixture.Context.Sites.Include(x => x.Credentials).SingleAsync();
        loaded.Name.Should().Be("Integration Site");
        loaded.Credentials.Should().ContainSingle();
        loaded.Credentials.Single().UserName.Should().Be("editor");
    }

    [Fact]
    public async Task Credential_Requires_An_Existing_Site()
    {
        await using var fixture = await SqliteFixture.CreateAsync();
        fixture.Context.SiteCredentials.Add(new SiteCredential(Guid.NewGuid(), "editor", "stored-value", DateTime.UtcNow));

        var action = async () => await fixture.Context.SaveChangesAsync();
        await action.Should().ThrowAsync<DbUpdateException>();
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
