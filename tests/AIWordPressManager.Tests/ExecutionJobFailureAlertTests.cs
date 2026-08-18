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

public sealed class ExecutionJobFailureAlertTests
{
    [Fact]
    public async Task Job_Failure_Alert_Uses_Enabled_Site_Recipients_And_Is_Idempotent()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var site = await fixture.AddSiteAsync(owner.Id);
        fixture.Context.SiteEmailRecipients.Add(new SiteEmailRecipient(site.Id, owner.Id, "ops@example.com", "Ops", DateTime.UtcNow));
        var disabled = new SiteEmailRecipient(site.Id, owner.Id, "disabled@example.com", null, DateTime.UtcNow);
        disabled.Update(disabled.EmailAddress, null, false, DateTime.UtcNow);
        fixture.Context.SiteEmailRecipients.Add(disabled);
        await fixture.Context.SaveChangesAsync();
        var jobId = Guid.NewGuid();

        var first = await fixture.AlertService.EnqueueSiteJobFailureAsync(
            owner.Id, site.Id, jobId, "SEO Audit", "API token=do-not-leak failed.", DateTime.UtcNow, "en");
        var second = await fixture.AlertService.EnqueueSiteJobFailureAsync(
            owner.Id, site.Id, jobId, "SEO Audit", "Different retry text.", DateTime.UtcNow, "en");

        first.Enqueued.Should().BeTrue();
        first.AlreadyQueued.Should().BeFalse();
        second.Enqueued.Should().BeTrue();
        second.AlreadyQueued.Should().BeTrue();
        second.OutboxMessageId.Should().Be(first.OutboxMessageId);
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(1);

        var stored = await fixture.Context.EmailOutboxMessages.AsNoTracking().SingleAsync();
        stored.TemplateKey.Should().Be(EmailTemplateKeys.SiteJobFailure);
        stored.Subject.Should().Contain("SEO Audit");
        stored.RecipientsJson.Should().Contain("ops@example.com");
        stored.RecipientsJson.Should().NotContain("disabled@example.com");
        stored.TextBody.Should().NotContain("do-not-leak");
        stored.IdempotencyKey.Should().Be($"alert:site-job-failure:{site.Id:N}:{jobId:N}");
        stored.CorrelationId.Should().Be($"job:{jobId:N}");
    }

    [Fact]
    public async Task Job_Failure_Alert_Skips_When_No_Enabled_Recipients_Exist()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var site = await fixture.AddSiteAsync(owner.Id);

        var result = await fixture.AlertService.EnqueueSiteJobFailureAsync(
            owner.Id, site.Id, Guid.NewGuid(), "Link Scan", "Failed", DateTime.UtcNow);

        result.Enqueued.Should().BeFalse();
        result.SkipReason.Should().Contain("No enabled");
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(0);
    }

    [Fact]
    public async Task Job_Failure_Alert_Rejects_Cross_Tenant_Site()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var other = await fixture.AddUserAsync();
        var site = await fixture.AddSiteAsync(other.Id);
        fixture.Context.SiteEmailRecipients.Add(new SiteEmailRecipient(site.Id, other.Id, "other@example.com", null, DateTime.UtcNow));
        await fixture.Context.SaveChangesAsync();

        var action = async () => await fixture.AlertService.EnqueueSiteJobFailureAsync(
            owner.Id, site.Id, Guid.NewGuid(), "Audit", "Failed", DateTime.UtcNow);

        await action.Should().ThrowAsync<UnauthorizedAccessException>();
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(0);
    }

    [Fact]
    public async Task Relay_Converts_Persisted_Failed_Job_To_Arabic_Alert_And_Redacts_Secrets()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var site = await fixture.AddSiteAsync(owner.Id, "ar-KW");
        fixture.Context.SiteEmailRecipients.Add(new SiteEmailRecipient(site.Id, owner.Id, "ops@example.com", null, DateTime.UtcNow));
        var job = new ExecutionJob(site.Id, "Content Audit", DateTime.UtcNow.AddMinutes(-2));
        job.Fail("Authorization=top-secret Bearer abc.def.ghi failed.", DateTime.UtcNow.AddMinutes(-1));
        fixture.Context.ExecutionJobs.Add(job);
        await fixture.Context.SaveChangesAsync();

        var first = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));
        var second = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1), first.HandledJobIds.ToHashSet());

        first.Enqueued.Should().Be(1);
        first.Failed.Should().Be(0);
        first.HandledJobIds.Should().Contain(job.Id);
        second.Enqueued.Should().Be(0);
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(1);

        var stored = await fixture.Context.EmailOutboxMessages.AsNoTracking().SingleAsync();
        stored.TemplateKey.Should().Be(EmailTemplateKeys.SiteJobFailure);
        stored.Subject.Should().Contain("فشل مهمة");
        stored.Subject.Should().Contain("Content Audit");
        stored.TextBody.Should().Contain(site.Name);
        stored.TextBody.Should().NotContain("top-secret");
        stored.TextBody.Should().NotContain("abc.def.ghi");
        stored.CorrelationId.Should().Be($"job:{job.Id:N}");
    }

    [Fact]
    public async Task Relay_Ignores_Completed_Jobs()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var site = await fixture.AddSiteAsync(owner.Id);
        fixture.Context.SiteEmailRecipients.Add(new SiteEmailRecipient(site.Id, owner.Id, "ops@example.com", null, DateTime.UtcNow));
        var job = new ExecutionJob(site.Id, "Content Audit", DateTime.UtcNow.AddMinutes(-2));
        job.Complete(DateTime.UtcNow.AddMinutes(-1));
        fixture.Context.ExecutionJobs.Add(job);
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
            Relay = new ExecutionJobFailureAlertRelay(context, AlertService, NullLogger<ExecutionJobFailureAlertRelay>.Instance);
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        public OperationalEmailAlertService AlertService { get; }
        public ExecutionJobFailureAlertRelay Relay { get; }

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
