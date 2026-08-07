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

public sealed class AccountEmailSettingsServiceTests
{
    [Fact]
    public async Task Account_Allows_At_Most_Three_Dashboard_Recipients()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var service = fixture.CreateService(owner.Id);

        await service.AddRecipientAsync("one@example.com", "One");
        await service.AddRecipientAsync("two@example.com", "Two");
        await service.AddRecipientAsync("three@example.com", "Three");

        var action = async () => await service.AddRecipientAsync("four@example.com", "Four");
        await action.Should().ThrowAsync<InvalidOperationException>().WithMessage("*maximum of three*");
        (await service.GetAsync()).Recipients.Should().HaveCount(3);
    }

    [Fact]
    public async Task Duplicate_Dashboard_Recipient_Is_Rejected_Case_Insensitively()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var service = fixture.CreateService(owner.Id);

        await service.AddRecipientAsync("Alerts@Example.com", null);
        var action = async () => await service.AddRecipientAsync("alerts@example.com", null);

        await action.Should().ThrowAsync<InvalidOperationException>().WithMessage("*already configured*");
    }

    [Fact]
    public async Task Account_Smtp_Password_Is_Protected_And_Blank_Update_Preserves_It()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var service = fixture.CreateService(owner.Id);

        var first = await service.SaveProfileAsync(new AccountMailProfileInput(
            "smtp.example.com", 587, "mailer@example.com", "TopSecret!123",
            "alerts@example.com", "Dashboard", "reply@example.com", true, true));

        first.HasSavedPassword.Should().BeTrue();
        var stored = await fixture.Context.AccountMailProfiles.AsNoTracking().SingleAsync();
        stored.ProtectedPassword.Should().NotBeNullOrWhiteSpace();
        stored.ProtectedPassword.Should().NotBe("TopSecret!123");
        var protectedValue = stored.ProtectedPassword;

        await service.SaveProfileAsync(new AccountMailProfileInput(
            "smtp2.example.com", 587, "mailer@example.com", string.Empty,
            "alerts@example.com", "Dashboard", "reply@example.com", true, true));

        fixture.Context.ChangeTracker.Clear();
        var after = await fixture.Context.AccountMailProfiles.AsNoTracking().SingleAsync();
        after.ProtectedPassword.Should().Be(protectedValue);
    }

    [Fact]
    public async Task Diagnostics_Profile_Is_Available_Before_Outbound_Email_Is_Enabled()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync();
        var service = fixture.CreateService(owner.Id);

        await service.SaveProfileAsync(new AccountMailProfileInput(
            "smtp.example.com", 587, "mailer@example.com", "Secret!123",
            "alerts@example.com", "Dashboard", string.Empty, true, false));

        (await service.GetDeliveryProfileAsync()).Should().BeNull();
        var testProfile = await service.GetTestProfileAsync();
        testProfile.Host.Should().Be("smtp.example.com");
        testProfile.Password.Should().Be("Secret!123");
        testProfile.FromAddress.Should().Be("alerts@example.com");
    }

    [Fact]
    public async Task Each_Account_Only_Sees_Its_Own_Email_Settings()
    {
        await using var fixture = await Fixture.CreateAsync();
        var first = await fixture.AddUserAsync();
        var second = await fixture.AddUserAsync();
        var firstService = fixture.CreateService(first.Id);
        var secondService = fixture.CreateService(second.Id);

        await firstService.AddRecipientAsync("first@example.com", "First");
        await secondService.AddRecipientAsync("second@example.com", "Second");

        var firstState = await firstService.GetAsync();
        var secondState = await secondService.GetAsync();
        firstState.Recipients.Should().ContainSingle(x => x.EmailAddress == "first@example.com");
        secondState.Recipients.Should().ContainSingle(x => x.EmailAddress == "second@example.com");
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

        public async Task<AuthUser> AddUserAsync()
        {
            var user = new AuthUser($"user-{Guid.NewGuid():N}", "test-password-hash", DateTime.UtcNow);
            Context.AuthUsers.Add(user);
            await Context.SaveChangesAsync();
            return user;
        }

        public AccountEmailSettingsService CreateService(Guid userId)
        {
            var http = new DefaultHttpContext
            {
                User = new ClaimsPrincipal(new ClaimsIdentity(
                    new[] { new Claim(ClaimTypes.NameIdentifier, userId.ToString()) }, "Test"))
            };
            var accessor = new HttpContextAccessor { HttpContext = http };
            return new AccountEmailSettingsService(Context, new CurrentUserContext(accessor), new FakeSecretProtectionService());
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
