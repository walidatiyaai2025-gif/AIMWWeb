using System.Text;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Billing;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class PayPalWebhookConfigurationTests
{
    [Fact]
    public async Task WebhookId_Is_Stored_Safely_Preserved_When_Omitted_And_Available_To_Runtime()
    {
        await using var fixture = await Fixture.CreateAsync();
        await fixture.Configuration.SaveAsync(
            new(true, PayPalEnvironment.Sandbox, "client", "8XX123456789"),
            "secret");

        var first = await fixture.Configuration.GetAsync();
        first.WebhookId.Should().Be("8XX123456789");
        (await fixture.Configuration.GetRequiredAsync()).WebhookId.Should().Be("8XX123456789");

        await fixture.Configuration.SaveAsync(
            new(true, PayPalEnvironment.Live, "client-live"),
            null);
        var preserved = await fixture.Configuration.GetAsync();
        preserved.WebhookId.Should().Be("8XX123456789");
        preserved.Environment.Should().Be(PayPalEnvironment.Live);
    }

    [Theory]
    [InlineData("bad-webhook-id")]
    [InlineData("with space")]
    [InlineData("123456789012345678901234567890123456789012345678901")]
    public async Task Invalid_WebhookId_Is_Rejected_Before_Persistence(string webhookId)
    {
        await using var fixture = await Fixture.CreateAsync();

        var action = () => fixture.Configuration.SaveAsync(
            new(false, PayPalEnvironment.Sandbox, "client", webhookId),
            null);

        await action.Should().ThrowAsync<ArgumentException>().WithMessage("*webhook ID*");
        (await fixture.Context.ApplicationSettings.CountAsync()).Should().Be(0);
    }

    [Fact]
    public async Task Explicit_Empty_WebhookId_Clears_It_Without_Clearing_Client_Secret()
    {
        await using var fixture = await Fixture.CreateAsync();
        await fixture.Configuration.SaveAsync(
            new(true, PayPalEnvironment.Sandbox, "client", "8XX123456789"),
            "secret");

        var saved = await fixture.Configuration.SaveAsync(
            new(true, PayPalEnvironment.Sandbox, "client", ""),
            null);

        saved.WebhookId.Should().BeEmpty();
        saved.HasClientSecret.Should().BeTrue();
        (await fixture.Configuration.GetRequiredAsync()).ClientSecret.Should().Be("secret");
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private Fixture(SqliteConnection connection, AppDbContext context)
        {
            Connection = connection;
            Context = context;
            Configuration = new PayPalConfigurationService(context, new FakeSecretProtectionService());
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        public PayPalConfigurationService Configuration { get; }

        public static async Task<Fixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
            var context = new AppDbContext(options);
            await context.Database.EnsureCreatedAsync();
            return new Fixture(connection, context);
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
            Task.FromResult("protected:" + Convert.ToBase64String(Encoding.UTF8.GetBytes(plainText)));

        public Task<string> UnprotectAsync(string protectedValue, CancellationToken cancellationToken = default)
        {
            var value = protectedValue.StartsWith("protected:", StringComparison.Ordinal)
                ? protectedValue[10..]
                : throw new InvalidOperationException("Invalid protected value.");
            return Task.FromResult(Encoding.UTF8.GetString(Convert.FromBase64String(value)));
        }
    }
}
