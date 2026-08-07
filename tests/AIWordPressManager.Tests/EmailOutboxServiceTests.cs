using AIWordPressManager.Application.Abstractions.Email;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class EmailOutboxServiceTests
{
    [Fact]
    public async Task Enqueue_With_Same_Idempotency_Key_Returns_Existing_Message()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var request = fixture.Request(owner.Id, "digest-2026-08-08");

        var first = await fixture.Service.EnqueueAsync(request);
        var second = await fixture.Service.EnqueueAsync(request);

        second.Id.Should().Be(first.Id);
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(1);
    }

    [Fact]
    public async Task Claim_Increments_Attempt_And_Prevents_Second_Claim()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        await fixture.Service.EnqueueAsync(fixture.Request(owner.Id, "claim-once"));
        var now = DateTime.UtcNow.AddMinutes(1);

        var first = await fixture.Service.ClaimDueAsync(now);
        var second = await fixture.Service.ClaimDueAsync(now);

        first.Should().NotBeNull();
        first!.AttemptNumber.Should().Be(1);
        first.ClaimToken.Should().NotBeNullOrWhiteSpace();
        second.Should().BeNull();
    }

    [Fact]
    public async Task Failed_Attempt_Waits_Before_Retry_And_Can_Be_Claimed_Again()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        await fixture.Service.EnqueueAsync(fixture.Request(owner.Id, "retry-message"));
        var firstTime = DateTime.UtcNow.AddMinutes(1);
        var claim = await fixture.Service.ClaimDueAsync(firstTime);

        await fixture.Service.MarkFailedAsync(claim!.Id, claim.ClaimToken, "SMTP", "Authentication rejected.", firstTime);

        fixture.Context.ChangeTracker.Clear();
        var stored = await fixture.Context.EmailOutboxMessages.AsNoTracking().SingleAsync();
        stored.Status.Should().Be(EmailOutboxMessage.RetryWaitingStatus);
        stored.AttemptCount.Should().Be(1);
        stored.NextAttemptAtUtc.Should().BeAfter(firstTime);
        (await fixture.Service.ClaimDueAsync(firstTime)).Should().BeNull();

        var second = await fixture.Service.ClaimDueAsync(stored.NextAttemptAtUtc.AddSeconds(1));
        second.Should().NotBeNull();
        second!.AttemptNumber.Should().Be(2);
    }

    [Fact]
    public async Task Sent_Message_Records_Delivery_Attempt_And_Is_Not_Reclaimed()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        await fixture.Service.EnqueueAsync(fixture.Request(owner.Id, "sent-message"));
        var now = DateTime.UtcNow.AddMinutes(1);
        var claim = await fixture.Service.ClaimDueAsync(now);

        await fixture.Service.MarkSentAsync(claim!.Id, claim.ClaimToken, "SMTP accepted message.", now.AddSeconds(2));

        fixture.Context.ChangeTracker.Clear();
        var stored = await fixture.Context.EmailOutboxMessages.AsNoTracking().SingleAsync();
        stored.Status.Should().Be(EmailOutboxMessage.SentStatus);
        stored.SentAtUtc.Should().NotBeNull();
        (await fixture.Context.EmailDeliveryAttempts.CountAsync()).Should().Be(1);
        (await fixture.Service.ClaimDueAsync(now.AddHours(1))).Should().BeNull();
    }

    [Fact]
    public async Task Stale_Sending_Claim_Is_Recovered_After_Worker_Restart()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        await fixture.Service.EnqueueAsync(fixture.Request(owner.Id, "stale-message"));
        var claimTime = DateTime.UtcNow.AddMinutes(1);
        await fixture.Service.ClaimDueAsync(claimTime);

        var recovered = await fixture.Service.RecoverStaleClaimsAsync(claimTime.AddMinutes(5), claimTime.AddMinutes(10));

        recovered.Should().Be(1);
        fixture.Context.ChangeTracker.Clear();
        var stored = await fixture.Context.EmailOutboxMessages.AsNoTracking().SingleAsync();
        stored.Status.Should().Be(EmailOutboxMessage.RetryWaitingStatus);
        stored.ClaimToken.Should().BeNull();
        stored.LastError.Should().Contain("worker stopped");
    }

    [Fact]
    public async Task Enqueue_Rejects_Site_Owned_By_Another_User()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var other = await fixture.AddUserAsync();
        var site = new Site("Other site", new Uri("https://other.example.test"), DateTime.UtcNow, other.Id);
        fixture.Context.Sites.Add(site);
        await fixture.Context.SaveChangesAsync();

        var request = fixture.Request(owner.Id, "wrong-owner") with { SiteId = site.Id };
        var action = async () => await fixture.Service.EnqueueAsync(request);

        await action.Should().ThrowAsync<UnauthorizedAccessException>();
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private Fixture(SqliteConnection connection, AppDbContext context)
        {
            Connection = connection;
            Context = context;
            Service = new EmailOutboxService(context);
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        public EmailOutboxService Service { get; }

        public static async Task<Fixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
            var context = new AppDbContext(options);
            await context.Database.EnsureCreatedAsync();
            return new Fixture(connection, context);
        }

        public async Task<AuthUser> AddUserAsync()
        {
            var user = new AuthUser($"user-{Guid.NewGuid():N}", "test-password-hash", DateTime.UtcNow);
            Context.AuthUsers.Add(user);
            await Context.SaveChangesAsync();
            return user;
        }

        public EmailOutboxEnqueueRequest Request(Guid ownerUserId, string idempotencyKey) => new(
            ownerUserId,
            null,
            null,
            "dashboard.digest",
            "Dashboard digest",
            "<p>Digest</p>",
            "Digest",
            ["alerts@example.com"],
            idempotencyKey,
            Guid.NewGuid().ToString("N"),
            3);

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}
