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

public sealed class MailProfileInheritanceTests
{
    [Fact]
    public async Task Site_Using_Account_Profile_Resolves_Account_Smtp_Settings()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
        await connection.OpenAsync();
        var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
        await using var db = new AppDbContext(options);
        await db.Database.EnsureCreatedAsync();

        var now = DateTime.UtcNow;
        var user = new AuthUser($"user-{Guid.NewGuid():N}", "hash", now);
        db.AuthUsers.Add(user);
        var site = new Site("Inherited Mail Site", new Uri("https://inherit.example.test"), now, user.Id);
        db.Sites.Add(site);
        await db.SaveChangesAsync();

        var http = new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(
                new[] { new Claim(ClaimTypes.NameIdentifier, user.Id.ToString()) }, "Test"))
        };
        var currentUser = new CurrentUserContext(new HttpContextAccessor { HttpContext = http });
        var secrets = new FakeSecretProtectionService();
        var accountService = new AccountEmailSettingsService(db, currentUser, secrets);
        var siteService = new SiteMailProfileService(db, currentUser, secrets);

        await accountService.SaveProfileAsync(new AccountMailProfileInput(
            "smtp.account.example", 465, "account-user", "AccountSecret!",
            "dashboard@example.com", "Dashboard", "reply@example.com", true, true));

        await siteService.SaveAsync(site.Id, new SiteMailProfileInput(
            true, string.Empty, 587, string.Empty, string.Empty,
            string.Empty, string.Empty, string.Empty, true, true));

        var resolved = await siteService.GetDeliveryProfileAsync(site.Id);

        resolved.Should().NotBeNull();
        resolved!.Host.Should().Be("smtp.account.example");
        resolved.Port.Should().Be(465);
        resolved.UserName.Should().Be("account-user");
        resolved.Password.Should().Be("AccountSecret!");
        resolved.FromAddress.Should().Be("dashboard@example.com");
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
