using System.Security.Claims;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class SiteEmailRecipientServiceTests
{
    [Fact]
    public async Task Site_Respects_Configured_Three_Recipient_Plan_Limit()
    {
        await using var fixture = await Fixture.CreateAsync();
        var ownerId = Guid.NewGuid();
        var site = await fixture.AddSiteAsync(ownerId);
        var service = fixture.CreateService(ownerId);

        await service.AddAsync(site.Id, "one@example.com", "One");
        await service.AddAsync(site.Id, "two@example.com", "Two");
        await service.AddAsync(site.Id, "three@example.com", "Three");

        var action = async () => await service.AddAsync(site.Id, "four@example.com", "Four");
        var error = await action.Should().ThrowAsync<AccountEntitlementDeniedException>();
        error.Which.Code.Should().Be("subscription_usage_limit_reached");
        error.Which.Limit.Should().Be(3);

        (await service.GetAsync(site.Id)).Should().HaveCount(3);
    }

    [Fact]
    public async Task Duplicate_Email_Is_Rejected_Case_Insensitively()
    {
        await using var fixture = await Fixture.CreateAsync();
        var ownerId = Guid.NewGuid();
        var site = await fixture.AddSiteAsync(ownerId);
        var service = fixture.CreateService(ownerId);

        await service.AddAsync(site.Id, "Alerts@Example.com", null);
        var action = async () => await service.AddAsync(site.Id, "alerts@example.com", null);

        await action.Should().ThrowAsync<InvalidOperationException>()
            .WithMessage("*already configured*");
    }

    [Fact]
    public async Task User_Cannot_Read_Or_Modify_Another_Users_Site_Recipients()
    {
        await using var fixture = await Fixture.CreateAsync();
        var ownerId = Guid.NewGuid();
        var otherUserId = Guid.NewGuid();
        var site = await fixture.AddSiteAsync(ownerId);
        await fixture.CreateService(ownerId).AddAsync(site.Id, "owner@example.com", null);

        var otherService = fixture.CreateService(otherUserId);
        var read = async () => await otherService.GetAsync(site.Id);
        var add = async () => await otherService.AddAsync(site.Id, "other@example.com", null);

        await read.Should().ThrowAsync<UnauthorizedAccessException>();
        await add.Should().ThrowAsync<UnauthorizedAccessException>();
    }

    [Fact]
    public async Task Recipient_Can_Be_Updated_Disabled_And_Deleted()
    {
        await using var fixture = await Fixture.CreateAsync();
        var ownerId = Guid.NewGuid();
        var site = await fixture.AddSiteAsync(ownerId);
        var service = fixture.CreateService(ownerId);
        var id = await service.AddAsync(site.Id, "first@example.com", "Primary");

        await service.UpdateAsync(site.Id, id, "updated@example.com", "Updated", false);
        var updated = (await service.GetAsync(site.Id)).Single();
        updated.EmailAddress.Should().Be("updated@example.com");
        updated.DisplayName.Should().Be("Updated");
        updated.IsEnabled.Should().BeFalse();

        await service.DeleteAsync(site.Id, id);
        (await service.GetAsync(site.Id)).Should().BeEmpty();
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private Fixture(SqliteConnection connection, AppDbContext context)
        {
            Connection = connection;
            Context = context;
        }

        private SqliteConnection Connection { get; }
        private AppDbContext Context { get; }

        public static async Task<Fixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
            var context = new AppDbContext(options);
            await context.Database.EnsureCreatedAsync();
            return new Fixture(connection, context);
        }

        public async Task<Site> AddSiteAsync(Guid ownerId)
        {
            var site = new Site($"Site-{Guid.NewGuid():N}", new Uri($"https://{Guid.NewGuid():N}.example.test"), DateTime.UtcNow, ownerId);
            Context.Sites.Add(site);
            await Context.SaveChangesAsync();
            return site;
        }

        public SiteEmailRecipientService CreateService(Guid userId)
        {
            var http = new DefaultHttpContext
            {
                User = new ClaimsPrincipal(new ClaimsIdentity(
                    new[]
                    {
                        new Claim(ClaimTypes.NameIdentifier, userId.ToString()),
                        new Claim(ClaimTypes.Name, $"user-{userId:N}")
                    },
                    "Test"))
            };
            var accessor = new HttpContextAccessor { HttpContext = http };
            return new SiteEmailRecipientService(
                Context,
                new CurrentUserContext(accessor),
                new ThreeRecipientEntitlementEnforcementService());
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }

    private sealed class ThreeRecipientEntitlementEnforcementService : IAccountEntitlementEnforcementService
    {
        public Task RequireBooleanCapabilityAsync(Guid ownerUserId, string entitlementKey, CancellationToken cancellationToken = default) =>
            Task.CompletedTask;

        public Task RequireAdditionalUsageAsync(
            Guid ownerUserId,
            string entitlementKey,
            long currentUsage,
            long requestedAdditional = 1,
            CancellationToken cancellationToken = default)
        {
            const long limit = 3;
            if (currentUsage + requestedAdditional > limit)
                throw new AccountEntitlementDeniedException(
                    "subscription_usage_limit_reached",
                    entitlementKey,
                    "The configured plan allows a maximum of three email recipients for this site.",
                    limit,
                    currentUsage,
                    requestedAdditional);
            return Task.CompletedTask;
        }
    }
}
