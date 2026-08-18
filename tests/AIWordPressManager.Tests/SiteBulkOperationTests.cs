using System.Security.Claims;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Domain.Enums;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class SiteBulkOperationPolicyTests
{
    [Fact]
    public void NormalizeIds_Removes_Empty_And_Duplicates_While_Preserving_Order()
    {
        var first = Guid.NewGuid();
        var second = Guid.NewGuid();

        var result = SiteBulkOperationPolicy.NormalizeIds([first, Guid.Empty, second, first]);

        result.Should().Equal(first, second);
    }

    [Fact]
    public void NormalizeIds_Rejects_Empty_Selection()
    {
        var action = () => SiteBulkOperationPolicy.NormalizeIds([]);

        action.Should().Throw<InvalidOperationException>()
            .WithMessage("*Select at least one site*");
    }

    [Fact]
    public void NormalizeIds_Rejects_Selections_Over_The_Bulk_Limit()
    {
        var ids = Enumerable.Range(0, SiteBulkOperationPolicy.MaxSitesPerOperation + 1)
            .Select(_ => Guid.NewGuid())
            .ToArray();

        var action = () => SiteBulkOperationPolicy.NormalizeIds(ids);

        action.Should().Throw<InvalidOperationException>()
            .WithMessage($"*{SiteBulkOperationPolicy.MaxSitesPerOperation}*");
    }

    [Fact]
    public void AreAllVisibleSelected_Requires_Every_Visible_Site()
    {
        var first = Guid.NewGuid();
        var second = Guid.NewGuid();
        IReadOnlySet<Guid> selected = new HashSet<Guid> { first, second };

        SiteBulkOperationPolicy.AreAllVisibleSelected([first, second], selected).Should().BeTrue();
        SiteBulkOperationPolicy.AreAllVisibleSelected([first, second, Guid.NewGuid()], selected).Should().BeFalse();
        SiteBulkOperationPolicy.AreAllVisibleSelected([], selected).Should().BeFalse();
    }
}

[Collection("Workflow persistence")]
public sealed class SiteBulkOperationOwnershipTests
{
    [Fact]
    public async Task SetSitesDisabledAsync_Validates_All_Ownership_Before_Mutating_Any_Site()
    {
        await using var fixture = await SiteServiceFixture.CreateAsync();
        var owned = fixture.AddSite("Owned", fixture.OwnerId);
        var foreign = fixture.AddSite("Foreign", Guid.NewGuid());
        await fixture.Db.SaveChangesAsync();

        var action = async () => await fixture.Service.SetSitesDisabledAsync([owned.Id, foreign.Id], true);

        await action.Should().ThrowAsync<UnauthorizedAccessException>();
        fixture.Db.ChangeTracker.Clear();
        (await fixture.Db.Sites.IgnoreQueryFilters().SingleAsync(x => x.Id == owned.Id))
            .ConnectionStatus.Should().Be(SiteConnectionStatus.Unknown);
    }

    [Fact]
    public async Task DeleteSitesAsync_SoftDeletes_All_Owned_Sites_In_One_Validated_Set()
    {
        await using var fixture = await SiteServiceFixture.CreateAsync();
        var first = fixture.AddSite("One", fixture.OwnerId);
        var second = fixture.AddSite("Two", fixture.OwnerId);
        await fixture.Db.SaveChangesAsync();

        var deleted = await fixture.Service.DeleteSitesAsync([first.Id, second.Id]);

        deleted.Should().Be(2);
        fixture.Db.ChangeTracker.Clear();
        (await fixture.Db.Sites.IgnoreQueryFilters().SingleAsync(x => x.Id == first.Id)).IsDeleted.Should().BeTrue();
        (await fixture.Db.Sites.IgnoreQueryFilters().SingleAsync(x => x.Id == second.Id)).IsDeleted.Should().BeTrue();
    }

    [Fact]
    public async Task View_only_permission_cannot_mutate_owned_sites()
    {
        await using var fixture = await SiteServiceFixture.CreateAsync(ApplicationPermissionCatalog.SitesView);
        var owned = fixture.AddSite("ReadOnly", fixture.OwnerId);
        await fixture.Db.SaveChangesAsync();

        var action = async () => await fixture.Service.SetSitesDisabledAsync([owned.Id], true);

        await action.Should().ThrowAsync<UnauthorizedAccessException>();
        fixture.Db.ChangeTracker.Clear();
        (await fixture.Db.Sites.IgnoreQueryFilters().SingleAsync(x => x.Id == owned.Id))
            .ConnectionStatus.Should().Be(SiteConnectionStatus.Unknown);
    }

    [Fact]
    public async Task Manage_permission_can_mutate_owned_sites()
    {
        await using var fixture = await SiteServiceFixture.CreateAsync(ApplicationPermissionCatalog.SitesManage);
        var owned = fixture.AddSite("Managed", fixture.OwnerId);
        await fixture.Db.SaveChangesAsync();

        var changed = await fixture.Service.SetSitesDisabledAsync([owned.Id], true);

        changed.Should().Be(1);
        fixture.Db.ChangeTracker.Clear();
        (await fixture.Db.Sites.IgnoreQueryFilters().SingleAsync(x => x.Id == owned.Id))
            .ConnectionStatus.Should().Be(SiteConnectionStatus.Disabled);
    }

    private sealed class SiteServiceFixture : IAsyncDisposable
    {
        private readonly SqliteConnection _connection;

        private SiteServiceFixture(SqliteConnection connection, AppDbContext db, Guid ownerId, SiteWebService service)
        {
            _connection = connection;
            Db = db;
            OwnerId = ownerId;
            Service = service;
        }

        public AppDbContext Db { get; }
        public Guid OwnerId { get; }
        public SiteWebService Service { get; }

        public static async Task<SiteServiceFixture> CreateAsync(string permission = ApplicationPermissionCatalog.SitesManage)
        {
            var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
            var db = new AppDbContext(options);
            await db.Database.EnsureCreatedAsync();

            var ownerId = Guid.NewGuid();
            var identity = new ClaimsIdentity(
                [
                    new Claim(ClaimTypes.NameIdentifier, ownerId.ToString()),
                    new Claim(ClaimTypes.Name, "bulk-test"),
                    new Claim(ApplicationPermissionCatalog.ClaimType, permission)
                ],
                "bulk-test");
            var accessor = new FixedHttpContextAccessor
            {
                HttpContext = new DefaultHttpContext { User = new ClaimsPrincipal(identity) }
            };
            var currentUser = new CurrentUserContext(accessor);
            var service = new SiteWebService(db, new UnusedConnectionTester(), new PassThroughSecretProtection(), currentUser);
            return new SiteServiceFixture(connection, db, ownerId, service);
        }

        public Site AddSite(string name, Guid ownerId)
        {
            var site = new Site(name, new Uri($"https://{name.ToLowerInvariant()}.example.com"), DateTime.UtcNow, ownerId);
            Db.Sites.Add(site);
            return site;
        }

        public async ValueTask DisposeAsync()
        {
            await Db.DisposeAsync();
            await _connection.DisposeAsync();
        }
    }

    private sealed class FixedHttpContextAccessor : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; }
    }

    private sealed class UnusedConnectionTester : IWordPressConnectionTester
    {
        public Task<WordPressConnectionResult> TestAsync(WordPressConnectionRequest request, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException("Connection testing is not used by these bulk ownership tests.");
    }

    private sealed class PassThroughSecretProtection : ISecretProtectionService
    {
        public Task<string> ProtectAsync(string plainText, CancellationToken cancellationToken = default) => Task.FromResult(plainText);
        public Task<string> UnprotectAsync(string protectedValue, CancellationToken cancellationToken = default) => Task.FromResult(protectedValue);
    }
}
