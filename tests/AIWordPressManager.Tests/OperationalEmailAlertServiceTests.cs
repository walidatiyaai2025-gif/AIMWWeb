using AIWordPressManager.Application.Abstractions.Email;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Email;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging.Abstractions;

namespace AIWordPressManager.Tests;

public sealed class OperationalEmailAlertServiceTests
{
    [Fact]
    public async Task Sync_Failure_Alert_Uses_Enabled_Site_Recipients_And_Is_Idempotent()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var site = await fixture.AddSiteAsync(owner.Id);
        fixture.Context.SiteEmailRecipients.Add(new SiteEmailRecipient(site.Id, owner.Id, "alerts@example.com", "Alerts", DateTime.UtcNow));
        var disabled = new SiteEmailRecipient(site.Id, owner.Id, "disabled@example.com", "Disabled", DateTime.UtcNow);
        disabled.Update(disabled.EmailAddress, disabled.DisplayName, false, DateTime.UtcNow);
        fixture.Context.SiteEmailRecipients.Add(disabled);
        await fixture.Context.SaveChangesAsync();
        var runId = Guid.NewGuid();

        var first = await fixture.AlertService.EnqueueSiteSyncFailureAsync(
            owner.Id, site.Id, runId, "Remote API failed.", DateTime.UtcNow, "en");
        var second = await fixture.AlertService.EnqueueSiteSyncFailureAsync(
            owner.Id, site.Id, runId, "Different retry text must not duplicate.", DateTime.UtcNow, "en");

        first.Enqueued.Should().BeTrue();
        first.AlreadyQueued.Should().BeFalse();
        second.Enqueued.Should().BeTrue();
        second.AlreadyQueued.Should().BeTrue();
        second.OutboxMessageId.Should().Be(first.OutboxMessageId);
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(1);

        var stored = await fixture.Context.EmailOutboxMessages.AsNoTracking().SingleAsync();
        stored.TemplateKey.Should().Be(EmailTemplateKeys.SiteSyncFailure);
        stored.RecipientsJson.Should().Contain("alerts@example.com");
        stored.RecipientsJson.Should().NotContain("disabled@example.com");
        stored.IdempotencyKey.Should().Contain(runId.ToString("N"));
        stored.SiteId.Should().Be(site.Id);
        stored.OwnerUserId.Should().Be(owner.Id);
    }

    [Fact]
    public async Task Sync_Failure_Alert_Skips_When_No_Enabled_Recipients_Exist()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var site = await fixture.AddSiteAsync(owner.Id);
        var disabled = new SiteEmailRecipient(site.Id, owner.Id, "disabled@example.com", null, DateTime.UtcNow);
        disabled.Update(disabled.EmailAddress, null, false, DateTime.UtcNow);
        fixture.Context.SiteEmailRecipients.Add(disabled);
        await fixture.Context.SaveChangesAsync();

        var result = await fixture.AlertService.EnqueueSiteSyncFailureAsync(
            owner.Id, site.Id, Guid.NewGuid(), "Failure", DateTime.UtcNow);

        result.Enqueued.Should().BeFalse();
        result.SkipReason.Should().Contain("No enabled");
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(0);
    }

    [Fact]
    public async Task Sync_Failure_Alert_Rejects_Cross_Tenant_Site()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var other = await fixture.AddUserAsync();
        var site = await fixture.AddSiteAsync(other.Id);
        fixture.Context.SiteEmailRecipients.Add(new SiteEmailRecipient(site.Id, other.Id, "other@example.com", null, DateTime.UtcNow));
        await fixture.Context.SaveChangesAsync();

        var action = async () => await fixture.AlertService.EnqueueSiteSyncFailureAsync(
            owner.Id, site.Id, Guid.NewGuid(), "Failure", DateTime.UtcNow);

        await action.Should().ThrowAsync<UnauthorizedAccessException>();
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(0);
    }

    [Fact]
    public void Failure_Reason_Sanitizer_Redacts_Secrets_And_Bounds_Output()
    {
        var longTail = new string('x', 1500);
        var input = $"password=hunter2 token:abc123 Authorization=secret Bearer eyJhbGci https://admin:pw@example.test/wp {longTail}\r\nnext";

        var sanitized = OperationalEmailAlertService.SanitizeFailureReason(input);

        sanitized.Should().NotContain("hunter2");
        sanitized.Should().NotContain("abc123");
        sanitized.Should().NotContain("Authorization=secret");
        sanitized.Should().NotContain("eyJhbGci");
        sanitized.Should().NotContain("admin:pw");
        sanitized.Should().Contain("[redacted]");
        sanitized.Should().NotContain("\r");
        sanitized.Should().NotContain("\n");
        sanitized.Length.Should().BeLessThanOrEqualTo(1000);
    }

    [Fact]
    public async Task Relay_Converts_Persisted_Failed_Sync_Run_To_Arabic_Outbox_Alert()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var site = await fixture.AddSiteAsync(owner.Id, "ar-KW");
        fixture.Context.SiteEmailRecipients.Add(new SiteEmailRecipient(site.Id, owner.Id, "ops@example.com", null, DateTime.UtcNow));
        var run = new SiteSyncRun(site.Id, DateTime.UtcNow.AddMinutes(-2));
        run.Fail("API token=do-not-leak failed.", DateTime.UtcNow.AddMinutes(-1));
        fixture.Context.Set<SiteSyncRun>().Add(run);
        await fixture.Context.SaveChangesAsync();

        var first = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));
        var second = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1), first.HandledRunIds.ToHashSet());

        first.Enqueued.Should().Be(1);
        first.Failed.Should().Be(0);
        first.HandledRunIds.Should().Contain(run.Id);
        second.Enqueued.Should().Be(0);
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(1);

        var stored = await fixture.Context.EmailOutboxMessages.AsNoTracking().SingleAsync();
        stored.Subject.Should().Contain("فشل مزامنة");
        stored.TextBody.Should().Contain(site.Name);
        stored.TextBody.Should().NotContain("do-not-leak");
        stored.CorrelationId.Should().Be($"sync:{run.Id:N}");
    }

    [Fact]
    public async Task Relay_Ignores_Successful_Sync_Runs()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var site = await fixture.AddSiteAsync(owner.Id);
        fixture.Context.SiteEmailRecipients.Add(new SiteEmailRecipient(site.Id, owner.Id, "ops@example.com", null, DateTime.UtcNow));
        var run = new SiteSyncRun(site.Id, DateTime.UtcNow.AddMinutes(-2));
        run.Complete("Completed", 3, false, DateTime.UtcNow.AddMinutes(-1));
        fixture.Context.Set<SiteSyncRun>().Add(run);
        await fixture.Context.SaveChangesAsync();

        var result = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));

        result.Scanned.Should().Be(0);
        result.Enqueued.Should().Be(0);
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(0);
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private Fixture(SqliteConnection connection, AppDbContext context)
        {
            Connection = connection;
            Context = context;
            var outbox = new EmailOutboxService(context);
            AlertService = new OperationalEmailAlertService(context, new EmailTemplateRenderer(), outbox);
            Relay = new SiteSyncFailureAlertRelay(context, AlertService, NullLogger<SiteSyncFailureAlertRelay>.Instance);
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        public OperationalEmailAlertService AlertService { get; }
        public SiteSyncFailureAlertRelay Relay { get; }

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

        public async Task<Site> AddSiteAsync(Guid ownerId, string? languageCode = null)
        {
            var site = new Site($"Site-{Guid.NewGuid():N}", new Uri($"https://{Guid.NewGuid():N}.example.test"), DateTime.UtcNow, ownerId);
            if (!string.IsNullOrWhiteSpace(languageCode))
                site.UpdateDiscovery(null, "6.8", languageCode, DateTime.UtcNow);
            Context.Sites.Add(site);
            await Context.SaveChangesAsync();
            return site;
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}
