using System.Security.Claims;
using AIWordPressManager.Application.Abstractions.Email;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class EmailDeliveryHistoryServiceTests
{
    [Fact]
    public async Task History_And_Details_Are_Isolated_Per_Account()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
        await connection.OpenAsync();
        var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
        await using var db = new AppDbContext(options);
        await db.Database.EnsureCreatedAsync();

        var first = new AuthUser("first-user", "hash", DateTime.UtcNow);
        var second = new AuthUser("second-user", "hash", DateTime.UtcNow);
        db.AuthUsers.AddRange(first, second);
        await db.SaveChangesAsync();

        var outbox = new EmailOutboxService(db);
        var firstMessage = await outbox.EnqueueAsync(new EmailOutboxEnqueueRequest(
            first.Id, null, null, "dashboard.digest", "First subject", "<p>first</p>", "first",
            ["first@example.com"], "first-key", "corr-first", 3));
        var secondMessage = await outbox.EnqueueAsync(new EmailOutboxEnqueueRequest(
            second.Id, null, null, "dashboard.digest", "Second subject", "<p>second</p>", "second",
            ["second@example.com"], "second-key", "corr-second", 3));

        var firstHistory = new EmailDeliveryHistoryService(db, CreateCurrentUser(first.Id));
        var items = await firstHistory.GetAsync();

        items.Should().ContainSingle();
        items[0].Id.Should().Be(firstMessage.Id);
        items[0].Subject.Should().Be("First subject");
        (await firstHistory.GetDetailsAsync(firstMessage.Id)).Should().NotBeNull();
        (await firstHistory.GetDetailsAsync(secondMessage.Id)).Should().BeNull();
    }

    [Fact]
    public async Task History_Can_Filter_By_Status_And_Correlation_Id()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
        await connection.OpenAsync();
        var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
        await using var db = new AppDbContext(options);
        await db.Database.EnsureCreatedAsync();

        var owner = new AuthUser("owner-user", "hash", DateTime.UtcNow);
        db.AuthUsers.Add(owner);
        await db.SaveChangesAsync();

        var outbox = new EmailOutboxService(db);
        var queued = await outbox.EnqueueAsync(new EmailOutboxEnqueueRequest(
            owner.Id, null, null, "dashboard.digest", "Queued", "<p>queued</p>", "queued",
            ["owner@example.com"], "queued-key", "corr-queued", 3));
        await outbox.EnqueueAsync(new EmailOutboxEnqueueRequest(
            owner.Id, null, null, "dashboard.digest", "Other", "<p>other</p>", "other",
            ["owner@example.com"], "other-key", "corr-other", 3));

        var history = new EmailDeliveryHistoryService(db, CreateCurrentUser(owner.Id));
        var items = await history.GetAsync(status: EmailOutboxMessage.QueuedStatus, correlationId: "corr-queued");

        items.Should().ContainSingle();
        items[0].Id.Should().Be(queued.Id);
    }

    private static CurrentUserContext CreateCurrentUser(Guid userId)
    {
        var http = new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(
                [new Claim(ClaimTypes.NameIdentifier, userId.ToString())], "Test"))
        };
        return new CurrentUserContext(new HttpContextAccessor { HttpContext = http });
    }
}
