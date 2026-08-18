using System.Net;
using System.Text;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Billing;
using AIWordPressManager.Infrastructure.Billing;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class PayPalConfigurationTests
{
    [Fact]
    public async Task Save_Encrypts_Secret_And_Safe_View_Never_Exposes_It()
    {
        await using var fixture = await Fixture.CreateAsync();

        var view = await fixture.Configuration.SaveAsync(
            new(true, PayPalEnvironment.Sandbox, "client-id-1"),
            "super-secret");

        view.Enabled.Should().BeTrue();
        view.HasClientSecret.Should().BeTrue();
        typeof(PayPalConfigurationView).GetProperty("ClientSecret").Should().BeNull();
        var stored = await fixture.Context.ApplicationSettings.AsNoTracking()
            .SingleAsync(x => x.Key == "Billing.PayPal.ProtectedClientSecret");
        stored.Value.Should().NotContain("super-secret");
        stored.Value.Should().StartWith("protected:");
        (await fixture.Configuration.GetRequiredAsync()).ClientSecret.Should().Be("super-secret");
    }

    [Fact]
    public async Task Save_Without_New_Secret_Preserves_Existing_Secret_And_Rotation_Replaces_It()
    {
        await using var fixture = await Fixture.CreateAsync();
        await fixture.Configuration.SaveAsync(new(true, PayPalEnvironment.Sandbox, "client-1"), "secret-1");
        var firstProtected = (await fixture.Context.ApplicationSettings.AsNoTracking()
            .SingleAsync(x => x.Key == "Billing.PayPal.ProtectedClientSecret")).Value;

        await fixture.Configuration.SaveAsync(new(true, PayPalEnvironment.Live, "client-2"), null);
        var preserved = (await fixture.Context.ApplicationSettings.AsNoTracking()
            .SingleAsync(x => x.Key == "Billing.PayPal.ProtectedClientSecret")).Value;
        preserved.Should().Be(firstProtected);
        (await fixture.Configuration.GetRequiredAsync()).ClientSecret.Should().Be("secret-1");

        await fixture.Configuration.SaveAsync(new(true, PayPalEnvironment.Live, "client-2"), "secret-2");
        var rotated = (await fixture.Context.ApplicationSettings.AsNoTracking()
            .SingleAsync(x => x.Key == "Billing.PayPal.ProtectedClientSecret")).Value;
        rotated.Should().NotBe(firstProtected);
        (await fixture.Configuration.GetRequiredAsync()).ClientSecret.Should().Be("secret-2");
    }

    [Fact]
    public async Task Enabled_Configuration_Requires_ClientId_And_Secret()
    {
        await using var fixture = await Fixture.CreateAsync();

        var missingId = () => fixture.Configuration.SaveAsync(
            new(true, PayPalEnvironment.Sandbox, ""), "secret");
        await missingId.Should().ThrowAsync<ArgumentException>();

        var missingSecret = () => fixture.Configuration.SaveAsync(
            new(true, PayPalEnvironment.Sandbox, "client"), null);
        await missingSecret.Should().ThrowAsync<InvalidOperationException>();
        (await fixture.Context.ApplicationSettings.CountAsync()).Should().Be(0);
    }

    [Fact]
    public async Task Clear_Secret_Disables_Integration_And_Removes_Stored_Credential()
    {
        await using var fixture = await Fixture.CreateAsync();
        await fixture.Configuration.SaveAsync(new(true, PayPalEnvironment.Sandbox, "client"), "secret");

        var cleared = await fixture.Configuration.ClearClientSecretAsync();

        cleared.Enabled.Should().BeFalse();
        cleared.HasClientSecret.Should().BeFalse();
        (await fixture.Context.ApplicationSettings.AnyAsync(x => x.Key == "Billing.PayPal.ProtectedClientSecret")).Should().BeFalse();
        var runtime = () => fixture.Configuration.GetRequiredAsync();
        await runtime.Should().ThrowAsync<InvalidOperationException>().WithMessage("*disabled*");
    }

    [Fact]
    public void Environment_Endpoints_Are_Explicit_And_Separate()
    {
        PayPalApiEndpoints.GetApiBaseUri(PayPalEnvironment.Sandbox).Should().Be(new Uri("https://api-m.sandbox.paypal.com/"));
        PayPalApiEndpoints.GetApiBaseUri(PayPalEnvironment.Live).Should().Be(new Uri("https://api-m.paypal.com/"));
    }

    [Fact]
    public async Task OAuth_Diagnostic_Uses_Selected_Endpoint_Basic_Auth_And_ClientCredentials_Without_Returning_Token()
    {
        await using var fixture = await Fixture.CreateAsync();
        await fixture.Configuration.SaveAsync(new(true, PayPalEnvironment.Sandbox, "client-a"), "secret-a");
        var handler = new RecordingHandler(_ => new HttpResponseMessage(HttpStatusCode.OK)
        {
            Content = new StringContent("{\"access_token\":\"token-that-must-not-escape\",\"expires_in\":3600}", Encoding.UTF8, "application/json")
        });
        using var client = new HttpClient(handler);
        var diagnostics = new PayPalConfigurationDiagnostics(client, fixture.Configuration, fixture.Configuration);

        var result = await diagnostics.ValidateAsync();

        result.Success.Should().BeTrue();
        result.Endpoint.Should().Be(new Uri("https://api-m.sandbox.paypal.com/v1/oauth2/token"));
        result.SanitizedSummary.Should().NotContain("token-that-must-not-escape");
        handler.RequestUri.Should().Be(result.Endpoint);
        handler.AuthorizationScheme.Should().Be("Basic");
        Encoding.UTF8.GetString(Convert.FromBase64String(handler.AuthorizationParameter!)).Should().Be("client-a:secret-a");
        handler.RequestBody.Should().Contain("grant_type=client_credentials");
    }

    [Fact]
    public async Task OAuth_Diagnostic_Does_Not_Leak_Rejected_Response_Or_Secret()
    {
        await using var fixture = await Fixture.CreateAsync();
        await fixture.Configuration.SaveAsync(new(true, PayPalEnvironment.Live, "client-live"), "secret-live");
        var handler = new RecordingHandler(_ => new HttpResponseMessage(HttpStatusCode.Unauthorized)
        {
            Content = new StringContent("secret-live access_token=should-not-leak", Encoding.UTF8, "text/plain")
        });
        using var client = new HttpClient(handler);
        var diagnostics = new PayPalConfigurationDiagnostics(client, fixture.Configuration, fixture.Configuration);

        var result = await diagnostics.ValidateAsync();

        result.Success.Should().BeFalse();
        result.HttpStatusCode.Should().Be(401);
        result.Endpoint.Should().Be(new Uri("https://api-m.paypal.com/v1/oauth2/token"));
        result.SanitizedSummary.Should().NotContain("secret-live");
        result.SanitizedSummary.Should().NotContain("should-not-leak");
    }

    [Fact]
    public async Task Diagnostic_Is_Fail_Closed_When_Config_Is_Disabled_Or_Response_Has_No_Token()
    {
        await using var fixture = await Fixture.CreateAsync();
        using var neverClient = new HttpClient(new RecordingHandler(_ => throw new InvalidOperationException("HTTP should not run")));
        var disabledDiagnostics = new PayPalConfigurationDiagnostics(neverClient, fixture.Configuration, fixture.Configuration);
        var disabled = await disabledDiagnostics.ValidateAsync();
        disabled.Success.Should().BeFalse();
        disabled.IsConfigured.Should().BeFalse();

        await fixture.Configuration.SaveAsync(new(true, PayPalEnvironment.Sandbox, "client"), "secret");
        using var client = new HttpClient(new RecordingHandler(_ => new HttpResponseMessage(HttpStatusCode.OK)
        {
            Content = new StringContent("{\"token_type\":\"Bearer\"}", Encoding.UTF8, "application/json")
        }));
        var diagnostics = new PayPalConfigurationDiagnostics(client, fixture.Configuration, fixture.Configuration);
        var missingToken = await diagnostics.ValidateAsync();
        missingToken.Success.Should().BeFalse();
        missingToken.SanitizedSummary.Should().Contain("did not return an access token");
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
            if (!protectedValue.StartsWith("protected:", StringComparison.Ordinal))
                throw new InvalidOperationException("Invalid protected value.");
            var plain = Encoding.UTF8.GetString(Convert.FromBase64String(protectedValue[10..]));
            return Task.FromResult(plain);
        }
    }

    private sealed class RecordingHandler(Func<HttpRequestMessage, HttpResponseMessage> responseFactory) : HttpMessageHandler
    {
        public Uri? RequestUri { get; private set; }
        public string? AuthorizationScheme { get; private set; }
        public string? AuthorizationParameter { get; private set; }
        public string? RequestBody { get; private set; }

        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            RequestUri = request.RequestUri;
            AuthorizationScheme = request.Headers.Authorization?.Scheme;
            AuthorizationParameter = request.Headers.Authorization?.Parameter;
            RequestBody = request.Content is null ? string.Empty : await request.Content.ReadAsStringAsync(cancellationToken);
            return responseFactory(request);
        }
    }
}
