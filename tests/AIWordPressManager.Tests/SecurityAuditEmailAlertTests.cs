using AIWordPressManager.Application.Abstractions.Email;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging.Abstractions;

namespace AIWordPressManager.Tests;

public sealed class SecurityAuditEmailAlertTests
{
    [Fact]
    public async Task Password_Reset_Queues_One_Account_Scoped_Security_Alert()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("owner.one");
        await fixture.AddRecipientAsync(owner.Id, "security@example.com");
        var record = await fixture.AppendAsync(
            "Account", "Password.Reset", "Succeeded", owner.Id,
            "ApplicationUser", owner.Id.ToString("D"), owner.UserName);

        var result = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));

        result.Enqueued.Should().Be(1);
        result.Failed.Should().Be(0);
        var stored = await fixture.Context.EmailOutboxMessages.AsNoTracking().SingleAsync();
        stored.OwnerUserId.Should().Be(owner.Id);
        stored.SiteId.Should().BeNull();
        stored.TemplateKey.Should().Be(EmailTemplateKeys.SecurityAlert);
        stored.RecipientsJson.Should().Contain("security@example.com");
        stored.IdempotencyKey.Should().Be($"alert:security-audit:{record.EventId:N}");
        stored.CorrelationId.Should().Be($"security:{record.EventId:N}");
    }

    [Fact]
    public async Task Ordinary_Failed_SignIn_Is_Excluded_But_Blocked_SignIn_Is_Alerted()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("locked.user");
        await fixture.AddRecipientAsync(owner.Id, "security@example.com");
        await fixture.AppendAsync(
            "Authentication", "SignIn", "Failed", owner.Id,
            "ApplicationUser", owner.Id.ToString("D"), owner.UserName,
            new Dictionary<string, string> { ["reason"] = "Invalid password" });
        var blocked = await fixture.AppendAsync(
            "Authentication", "SignIn", "Blocked", owner.Id,
            "ApplicationUser", owner.Id.ToString("D"), owner.UserName,
            new Dictionary<string, string> { ["reason"] = "Account lockout" });

        var result = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));

        result.Scanned.Should().Be(1);
        result.Enqueued.Should().Be(1);
        var stored = await fixture.Context.EmailOutboxMessages.AsNoTracking().SingleAsync();
        stored.IdempotencyKey.Should().Be($"alert:security-audit:{blocked.EventId:N}");
    }

    [Fact]
    public async Task ApplicationUser_Target_Owner_Is_Preferred_Over_Actor_Without_Cross_Owner_Fallback()
    {
        await using var fixture = await Fixture.CreateAsync();
        var target = await fixture.AddUserAsync("target.user");
        var actor = await fixture.AddUserAsync("admin.actor");
        await fixture.AddRecipientAsync(target.Id, "target@example.com");
        await fixture.AddRecipientAsync(actor.Id, "actor@example.com");
        await fixture.AppendAsync(
            "Account", "User.Disabled", "Succeeded", actor.Id,
            "ApplicationUser", target.Id.ToString("D"), target.UserName);

        var result = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));

        result.Enqueued.Should().Be(1);
        var stored = await fixture.Context.EmailOutboxMessages.AsNoTracking().SingleAsync();
        stored.OwnerUserId.Should().Be(target.Id);
        stored.RecipientsJson.Should().Contain("target@example.com");
        stored.RecipientsJson.Should().NotContain("actor@example.com");
    }

    [Fact]
    public async Task Disabled_Account_Recipients_Cause_Deterministic_Skip()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("owner.disabled-recipient");
        await fixture.AddRecipientAsync(owner.Id, "disabled@example.com", enabled: false);
        await fixture.AppendAsync(
            "Account", "Password.Changed", "Succeeded", owner.Id,
            "ApplicationUser", owner.Id.ToString("D"), owner.UserName);

        var result = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));

        result.Skipped.Should().Be(1);
        result.HandledEventIds.Should().HaveCount(1);
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(0);
    }

    [Fact]
    public async Task Duplicate_Relay_Passes_Reuse_The_Durable_Outbox_Message()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("owner.idempotent");
        await fixture.AddRecipientAsync(owner.Id, "security@example.com");
        var record = await fixture.AppendAsync(
            "Account", "Password.Reset", "Succeeded", owner.Id,
            "ApplicationUser", owner.Id.ToString("D"), owner.UserName);

        var first = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));
        var second = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));

        first.Enqueued.Should().Be(1);
        second.Enqueued.Should().Be(1);
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(1);
        (await fixture.Context.EmailOutboxMessages.AsNoTracking().SingleAsync()).IdempotencyKey
            .Should().Be($"alert:security-audit:{record.EventId:N}");
    }

    [Fact]
    public async Task Security_Email_Boundary_Redacts_Secrets_In_Target_And_Metadata()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("owner.redaction");
        await fixture.AddRecipientAsync(owner.Id, "security@example.com");
        await fixture.AppendAsync(
            "Account", "Password.Reset", "Succeeded", owner.Id,
            "ApplicationUser", owner.Id.ToString("D"),
            "password=hunter2 sensitive target",
            new Dictionary<string, string>
            {
                ["reason"] = "token=super-secret Bearer eyJhbGciOiJIUzI1NiJ9",
                ["note"] = new string('x', 500)
            });

        var result = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));

        result.Enqueued.Should().Be(1);
        var stored = await fixture.Context.EmailOutboxMessages.AsNoTracking().SingleAsync();
        stored.TextBody.Should().NotContain("hunter2");
        stored.TextBody.Should().NotContain("super-secret");
        stored.TextBody.Should().NotContain("eyJhbGciOiJIUzI1NiJ9");
        stored.TextBody.Should().Contain("[redacted]");
        SecurityAuditEmailAlertRelay.SanitizeForEmail(new string('x', 1500)).Length.Should().Be(1000);
    }

    [Fact]
    public async Task Role_Grant_And_Session_Revocation_Events_Are_High_Signal()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("owner.security-events");
        await fixture.AddRecipientAsync(owner.Id, "security@example.com");
        await fixture.AppendAsync(
            "Authorization", "Role.Updated", "Succeeded", owner.Id,
            "ApplicationRole", "Publisher", "Publisher",
            new Dictionary<string, string> { ["grantsChanged"] = bool.TrueString });
        await fixture.AppendAsync(
            "Session", "Session.Revoked", "Succeeded", owner.Id,
            "ApplicationSession", Guid.NewGuid().ToString("D"), owner.UserName);

        var result = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));

        result.Scanned.Should().Be(2);
        result.Enqueued.Should().Be(2);
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(2);
    }

    [Fact]
    public async Task One_Alert_Failure_Does_Not_Block_Later_Security_Events()
    {
        await using var fixture = await Fixture.CreateAsync(failFirstEnqueue: true);
        var owner = await fixture.AddUserAsync("owner.failure-isolation");
        await fixture.AddRecipientAsync(owner.Id, "security@example.com");
        await fixture.AppendAsync(
            "Account", "Password.Reset", "Succeeded", owner.Id,
            "ApplicationUser", owner.Id.ToString("D"), owner.UserName);
        await fixture.AppendAsync(
            "Session", "Session.Revoked", "Succeeded", owner.Id,
            "ApplicationSession", Guid.NewGuid().ToString("D"), owner.UserName);

        var first = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));

        first.Failed.Should().Be(1);
        first.Enqueued.Should().Be(1);
        first.HandledEventIds.Should().HaveCount(1);
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(1);

        var retryRelay = new SecurityAuditEmailAlertRelay(
            fixture.Context,
            new EmailTemplateRenderer(),
            new EmailOutboxService(fixture.Context),
            NullLogger<SecurityAuditEmailAlertRelay>.Instance);
        var retry = await retryRelay.RelayPendingAsync(
            DateTime.UtcNow.AddHours(-1),
            first.HandledEventIds.ToHashSet());

        retry.Failed.Should().Be(0);
        retry.Enqueued.Should().Be(1);
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(2);
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private Fixture(SqliteConnection connection, AppDbContext context, bool failFirstEnqueue)
        {
            Connection = connection;
            Context = context;
            IEmailOutbox outbox = new EmailOutboxService(context);
            if (failFirstEnqueue)
                outbox = new FailFirstEmailOutbox(outbox);
            Relay = new SecurityAuditEmailAlertRelay(
                context,
                new EmailTemplateRenderer(),
                outbox,
                NullLogger<SecurityAuditEmailAlertRelay>.Instance);
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        public SecurityAuditEmailAlertRelay Relay { get; }

        public static async Task<Fixture> CreateAsync(bool failFirstEnqueue = false)
        {
            var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
            var context = new AppDbContext(options);
            await context.Database.EnsureCreatedAsync();
            return new Fixture(connection, context, failFirstEnqueue);
        }

        public async Task<AuthUser> AddUserAsync(string userName)
        {
            var user = new AuthUser(userName, "test-password-hash", DateTime.UtcNow);
            Context.AuthUsers.Add(user);
            await Context.SaveChangesAsync();
            return user;
        }

        public async Task AddRecipientAsync(Guid ownerUserId, string emailAddress, bool enabled = true)
        {
            var recipient = new AccountEmailRecipient(ownerUserId, emailAddress, null, DateTime.UtcNow);
            if (!enabled)
                recipient.Update(emailAddress, null, false, DateTime.UtcNow);
            Context.AccountEmailRecipients.Add(recipient);
            await Context.SaveChangesAsync();
        }

        public Task<SecurityAuditRecord> AppendAsync(
            string category,
            string action,
            string outcome,
            Guid? actorUserId,
            string targetType,
            string targetId,
            string targetDisplayName,
            IReadOnlyDictionary<string, string>? metadata = null) =>
            new ApplicationSecurityAuditStore(Context).AppendAsync(new SecurityAuditEvent(
                category,
                action,
                outcome,
                actorUserId,
                actorUserId?.ToString("D"),
                targetType,
                targetId,
                targetDisplayName,
                "security-alert-test",
                "127.0.0.1",
                "tests",
                metadata));

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }

    private sealed class FailFirstEmailOutbox(IEmailOutbox inner) : IEmailOutbox
    {
        private bool _failed;

        public Task<EmailOutboxItem> EnqueueAsync(EmailOutboxEnqueueRequest request, CancellationToken cancellationToken = default)
        {
            if (!_failed)
            {
                _failed = true;
                throw new InvalidOperationException("Injected first security alert enqueue failure.");
            }
            return inner.EnqueueAsync(request, cancellationToken);
        }

        public Task<EmailOutboxClaim?> ClaimDueAsync(DateTime utcNow, CancellationToken cancellationToken = default) =>
            inner.ClaimDueAsync(utcNow, cancellationToken);

        public Task MarkSentAsync(Guid messageId, string claimToken, string? providerSummary, DateTime utcNow, CancellationToken cancellationToken = default) =>
            inner.MarkSentAsync(messageId, claimToken, providerSummary, utcNow, cancellationToken);

        public Task MarkFailedAsync(Guid messageId, string claimToken, string errorCategory, string sanitizedError, DateTime utcNow, CancellationToken cancellationToken = default) =>
            inner.MarkFailedAsync(messageId, claimToken, errorCategory, sanitizedError, utcNow, cancellationToken);

        public Task<int> RecoverStaleClaimsAsync(DateTime staleBeforeUtc, DateTime utcNow, CancellationToken cancellationToken = default) =>
            inner.RecoverStaleClaimsAsync(staleBeforeUtc, utcNow, cancellationToken);
    }
}
