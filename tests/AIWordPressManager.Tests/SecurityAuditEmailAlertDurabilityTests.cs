using AIWordPressManager.Application.Abstractions.Email;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging.Abstractions;

namespace AIWordPressManager.Tests;

public sealed class SecurityAuditEmailAlertDurabilityTests
{
    [Fact]
    public void Worker_recovery_window_matches_the_authoritative_audit_retention_window()
    {
        SecurityAuditEmailAlertWorker.RecoveryWindow
            .Should().Be(ApplicationSecurityAuditStore.RetentionWindow);
        SecurityAuditEmailAlertWorker.RecoveryWindow.Should().BeGreaterThan(TimeSpan.FromHours(24));
    }

    [Fact]
    public async Task Handled_first_pass_does_not_starve_the_501st_retained_security_event()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
        await connection.OpenAsync();
        var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
        await using var context = new AppDbContext(options);
        await context.Database.EnsureCreatedAsync();

        var owner = new AuthUser("security.backlog", "test-password-hash", DateTime.UtcNow);
        context.AuthUsers.Add(owner);
        context.AccountEmailRecipients.Add(new AccountEmailRecipient(
            owner.Id,
            "security-backlog@example.com",
            null,
            DateTime.UtcNow));
        await context.SaveChangesAsync();

        var store = new ApplicationSecurityAuditStore(context);
        for (var index = 0; index < 501; index++)
        {
            await store.AppendAsync(new SecurityAuditEvent(
                "Account",
                "Password.Reset",
                "Succeeded",
                owner.Id,
                owner.UserName,
                "ApplicationUser",
                owner.Id.ToString("D"),
                owner.UserName,
                $"backlog-{index}",
                "127.0.0.1",
                "tests"));
        }

        var retained = await store.ListRetainedAsync(
            DateTime.UtcNow.Subtract(ApplicationSecurityAuditStore.RetentionWindow));
        retained.Should().HaveCount(501);

        var oldest = retained.OrderBy(record => record.OccurredAtUtc).ThenBy(record => record.EventId).First();
        var alreadyHandled = retained
            .Where(record => record.EventId != oldest.EventId)
            .Select(record => record.EventId)
            .ToHashSet();
        alreadyHandled.Should().HaveCount(500);

        var relay = new SecurityAuditEmailAlertRelay(
            context,
            new EmailTemplateRenderer(),
            new EmailOutboxService(context),
            NullLogger<SecurityAuditEmailAlertRelay>.Instance);

        var result = await relay.RelayPendingAsync(
            DateTime.UtcNow.Subtract(ApplicationSecurityAuditStore.RetentionWindow),
            alreadyHandled);

        result.Scanned.Should().Be(1);
        result.Enqueued.Should().Be(1);
        result.Failed.Should().Be(0);
        result.HandledEventIds.Should().ContainSingle().Which.Should().Be(oldest.EventId);

        var outbox = await context.EmailOutboxMessages.AsNoTracking().SingleAsync();
        outbox.IdempotencyKey.Should().Be($"alert:security-audit:{oldest.EventId:N}");
    }
}
