using System.Security.Claims;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class SiteMailProfileServiceTests
{
    [Fact]
    public async Task Password_Is_Protected_And_Blank_Update_Preserves_Stored_Secret()
    {
        await using var fixture = await Fixture.CreateAsync();
        var ownerId = Guid.NewGuid();
        var site = await fixture.AddSiteAsync(ownerId);
        var service = fixture.CreateService(ownerId);

        var saved = await service.SaveAsync(site.Id, new SiteMailProfileInput(
            false, "smtp.example.com", 587, "mailer@example.com", "VerySecret!123",
            "alerts@example.com", "Alerts", "reply@example.com", true, true));

        saved.HasSavedPassword.Should().BeTrue();
        var stored = await fixture.Context.SiteMailProfiles.SingleAsync();
        stored.ProtectedPassword.Should().NotBeNullOrWhiteSpace();
        stored.ProtectedPassword.Should().NotBe("VerySecret!123");

        await service.SaveAsync(site.Id, new SiteMailProfileInput(
            false, "smtp2.example.com", 587, "mailer@example.com", string.Empty,
            "alerts@example.com", "Alerts", "reply@example.com", true, true));

        fixture.Context.ChangeTracker.Clear();
        var after = await fixture.Context.SiteMailProfiles.AsNoTracking().SingleAsync();
        after.ProtectedPassword.Should().Be(stored.ProtectedPassword);
    }

    [Fact]
    public async Task Another_User_Cannot_Read_Or_Save_Site_Mail_Profile()
    {
        await using var fixture = await Fixture.CreateAsync();
        var ownerId = Guid.NewGuid();
        var otherId = Guid.NewGuid();
        var site = await fixture.AddSiteAsync(ownerId);
        await fixture.CreateService(ownerId).SaveAsync(site.Id, new SiteMailProfileInput(
            false, "smtp.example.com", 587, "mailer", "secret",
            "alerts@example.com", "Alerts", string.Empty, true, true));

        var other = fixture.CreateService(otherId);
        var read = async () => await other.GetAsync(site.Id);
        var save = async () => await other.SaveAsync(site.Id, new SiteMailProfileInput(
            true, string.Empty, 587, string.Empty, string.Empty,
            string.Empty, string.Empty, string.Empty, true, false));

        await read.Should().ThrowAsync<UnauthorizedAccessException>();
        await save.Should().ThrowAsync<UnauthorizedAccessException>();
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private Fixture(SqliteConnection connection, AppDbContext context)
        {
            Connection = connection;
            Context = context;
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }

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

        public SiteMailProfileService CreateService(Guid userId)
        {
            var http = new DefaultHttpContext
            {
                User = new ClaimsPrincipal(new ClaimsIdentity(
                    new[] { new Claim(ClaimTypes.NameIdentifier, userId.ToString()) }, "Test"))
            };
            var accessor = new HttpContextAccessor { HttpContext = http };
            return new SiteMailProfileService(Context, new CurrentUserContext(accessor), new FakeSecretProtectionService());
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }

    private sealed class FakeSecretProtectionService : ISecretProtectionService
    {
        public Task<string> ProtectAsync(string plainText, CancellationToken cancellationToken = default) =>
            Task.FromResult("protected::" + Convert.ToBase64String(System.Text.Encoding.UTF8.GetBytes(plainText)));

        public Task<string> UnprotectAsync(string protectedValue, CancellationToken cancellationToken = default)
        {
            var payload = protectedValue["protected::".Length..];
            return Task.FromResult(System.Text.Encoding.UTF8.GetString(Convert.FromBase64String(payload)));
        }
    }
}
