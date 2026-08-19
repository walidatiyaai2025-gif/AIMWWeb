using System.Security.Claims;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Application.Abstractions.Email;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class EmailScheduleTests
{
    [Fact]
    public void Daily_Utc_Schedule_Calculates_Next_Occurrence()
    {
        var now = new DateTime(2026, 8, 8, 9, 30, 0, DateTimeKind.Utc);
        var next = EmailScheduleCalculator.CalculateNextRunUtc("UTC", EmailSchedule.DailyFrequency, new TimeSpan(10, 0, 0), null, null, now);
        next.Should().Be(new DateTime(2026, 8, 8, 10, 0, 0, DateTimeKind.Utc));
    }

    [Fact]
    public void Monthly_Day_31_Clamps_To_End_Of_Short_Month()
    {
        var now = new DateTime(2026, 4, 30, 12, 0, 0, DateTimeKind.Utc);
        var next = EmailScheduleCalculator.CalculateNextRunUtc("UTC", EmailSchedule.MonthlyFrequency, new TimeSpan(8, 0, 0), null, 31, now);
        next.Should().Be(new DateTime(2026, 5, 31, 8, 0, 0, DateTimeKind.Utc));
    }

    [Fact]
    public async Task Account_Schedule_Persists_Dashboard_Template_And_Arabic_Culture()
    {
        await using var fixture = await Fixture.CreateAsync();
        var user = await fixture.AddUserAsync();
        var service = fixture.CreateService(user.Id);

        var created = await service.CreateAccountScheduleAsync(new EmailScheduleInput(
            "DashboardDigest", EmailTemplateKeys.DashboardDigest, "UTC", EmailSchedule.DailyFrequency,
            new TimeSpan(8, 0, 0), null, null, 3, 5, true, "ar"));

        created.Scope.Should().Be(EmailSchedule.AccountScope);
        created.SiteId.Should().BeNull();
        created.Culture.Should().Be("ar");
        created.IsEnabled.Should().BeTrue();
        created.NextRunUtc.Should().BeAfter(DateTime.UtcNow.AddMinutes(-1));
    }

    [Fact]
    public async Task User_Cannot_Create_Schedule_For_Another_Users_Site()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var other = await fixture.AddUserAsync();
        var site = new Site("Owner site", new Uri("https://example.com"), DateTime.UtcNow, owner.Id);
        fixture.Context.Sites.Add(site);
        await fixture.Context.SaveChangesAsync();
        var service = fixture.CreateService(other.Id);

        var action = async () => await service.CreateSiteScheduleAsync(site.Id, new EmailScheduleInput(
            "SiteOperationalReport", EmailTemplateKeys.SiteOperationalReport, "UTC", EmailSchedule.DailyFrequency,
            new TimeSpan(8, 0, 0), null, null, 3, 5, true, "en"));

        await action.Should().ThrowAsync<UnauthorizedAccessException>();
    }

    private sealed class AllowAllEntitlementEnforcementService : IAccountEntitlementEnforcementService
    {
        public Task RequireBooleanCapabilityAsync(Guid ownerUserId, string entitlementKey, CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task RequireAdditionalUsageAsync(Guid ownerUserId, string entitlementKey, long currentUsage, long requestedAdditional = 1, CancellationToken cancellationToken = default) => Task.CompletedTask;
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private Fixture(SqliteConnection connection, AppDbContext context) { Connection = connection; Context = context; }
        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }

        public static async Task<Fixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
            await connection.OpenAsync();
            var context = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
            await context.Database.EnsureCreatedAsync();
            return new Fixture(connection, context);
        }

        public async Task<AuthUser> AddUserAsync()
        {
            var user = new AuthUser($"user-{Guid.NewGuid():N}", "hash", DateTime.UtcNow);
            Context.AuthUsers.Add(user);
            await Context.SaveChangesAsync();
            return user;
        }

        public EmailScheduleService CreateService(Guid userId)
        {
            var http = new DefaultHttpContext
            {
                User = new ClaimsPrincipal(new ClaimsIdentity(new[] { new Claim(ClaimTypes.NameIdentifier, userId.ToString()) }, "Test"))
            };
            return new EmailScheduleService(
                Context,
                new CurrentUserContext(new HttpContextAccessor { HttpContext = http }),
                new AllowAllEntitlementEnforcementService());
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}
