using System.Text.Json;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class ApplicationSessionStoreTests
{
    [Fact]
    public async Task Create_persists_unique_active_session_metadata()
    {
        await using var fixture = await Fixture.CreateAsync();
        var userId = Guid.NewGuid();

        var first = await fixture.Store.CreateAsync(userId, "alice", "User", "10.0.0.1", "Browser A", false);
        var second = await fixture.Store.CreateAsync(userId, "alice", "User", "10.0.0.2", "Browser B", true);

        first.SessionId.Should().NotBe(second.SessionId);
        (await fixture.Store.ValidateAsync(first.SessionId, userId)).IsValid.Should().BeTrue();
        var sessions = await fixture.Store.ListAsync(userId);
        sessions.Should().HaveCount(2);
        sessions.Should().Contain(x => x.IpAddress == "10.0.0.1" && x.UserAgent == "Browser A" && !x.Persistent);
        sessions.Should().Contain(x => x.IpAddress == "10.0.0.2" && x.UserAgent == "Browser B" && x.Persistent);
    }

    [Fact]
    public async Task Revoked_session_fails_validation_and_is_hidden_from_active_inventory()
    {
        await using var fixture = await Fixture.CreateAsync();
        var userId = Guid.NewGuid();
        var session = await fixture.Store.CreateAsync(userId, "alice", "User", null, null, false);

        await fixture.Store.RevokeAsync(session.SessionId, "Security change");

        var validation = await fixture.Store.ValidateAsync(session.SessionId, userId);
        validation.IsValid.Should().BeFalse();
        validation.Reason.Should().Contain("revoked");
        (await fixture.Store.ListAsync(userId)).Should().BeEmpty();
        (await fixture.Store.ListAsync(userId, includeInactive: true)).Should().ContainSingle(x =>
            x.SessionId == session.SessionId && x.RevokedReason == "Security change");
    }

    [Fact]
    public async Task Expired_session_fails_closed()
    {
        await using var fixture = await Fixture.CreateAsync();
        var sessionId = Guid.NewGuid();
        var userId = Guid.NewGuid();
        var now = DateTime.UtcNow;
        var payload = JsonSerializer.Serialize(new
        {
            version = 1,
            sessions = new[]
            {
                new
                {
                    sessionId,
                    userId,
                    userName = "expired.user",
                    role = "User",
                    createdAtUtc = now.AddHours(-10),
                    lastSeenAtUtc = now.AddHours(-9),
                    expiresAtUtc = now.AddHours(-1),
                    revokedAtUtc = (DateTime?)null,
                    revokedReason = "",
                    ipAddress = "",
                    userAgent = "",
                    persistent = false
                }
            }
        }, new JsonSerializerOptions(JsonSerializerDefaults.Web));
        fixture.Context.ApplicationSettings.Add(new ApplicationSetting(ApplicationSessionStore.SettingsKey, payload, now));
        await fixture.Context.SaveChangesAsync();

        var validation = await fixture.Store.ValidateAsync(sessionId, userId);

        validation.IsValid.Should().BeFalse();
        validation.Reason.Should().Contain("expired");
    }

    [Fact]
    public async Task Corrupt_registry_never_validates_a_session()
    {
        await using var fixture = await Fixture.CreateAsync();
        fixture.Context.ApplicationSettings.Add(new ApplicationSetting(ApplicationSessionStore.SettingsKey, "{broken-json", DateTime.UtcNow));
        await fixture.Context.SaveChangesAsync();

        var validation = await fixture.Store.ValidateAsync(Guid.NewGuid(), Guid.NewGuid());

        validation.IsValid.Should().BeFalse();
        validation.Reason.Should().Contain("registry");
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private readonly SqliteConnection _connection;
        public AppDbContext Context { get; }
        public ApplicationSessionStore Store { get; }

        private Fixture(SqliteConnection connection, AppDbContext context)
        {
            _connection = connection;
            Context = context;
            Store = new ApplicationSessionStore(context);
        }

        public static async Task<Fixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var context = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
            await context.Database.EnsureCreatedAsync();
            return new Fixture(connection, context);
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await _connection.DisposeAsync();
        }
    }
}