using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Application.Settings;
using AIWordPressManager.Infrastructure.AI;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Settings;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Configuration;

namespace AIWordPressManager.Tests;

[Collection("Workflow persistence")]
public sealed class AIProviderConfigurationTests
{
    [Fact]
    public async Task Saving_Api_Key_Protects_It_And_Blank_Update_Preserves_It()
    {
        await using var fixture = await SettingsFixture.CreateAsync();
        var settings = await fixture.Service.GetAiSettingsAsync();
        var openAi = settings.Providers.Single(x => x.Provider == "OpenAI");
        var updated = settings with
        {
            Providers = settings.Providers
                .Select(x => x.Provider == "OpenAI" ? x with { Enabled = true, Model = "gpt-test" } : x)
                .ToArray()
        };

        await fixture.Service.SaveAiSettingsAsync(
            updated,
            new Dictionary<string, string?> { ["OpenAI"] = "sk-super-secret" });

        fixture.Context.ChangeTracker.Clear();
        var stored = await fixture.Context.ApplicationSettings.SingleAsync(x => x.Key == "AI.OpenAI.ProtectedApiKey");
        stored.Value.Should().Be("protected::sk-super-secret");
        stored.Value.Should().NotBe("sk-super-secret");
        (await fixture.Service.GetAiProviderApiKeyAsync("OpenAI")).Should().Be("sk-super-secret");

        var afterFirstSave = await fixture.Service.GetAiSettingsAsync();
        await fixture.Service.SaveAiSettingsAsync(
            afterFirstSave with
            {
                Providers = afterFirstSave.Providers
                    .Select(x => x.Provider == "OpenAI" ? x with { Model = "gpt-test-2" } : x)
                    .ToArray()
            },
            new Dictionary<string, string?> { ["OpenAI"] = "   " });

        (await fixture.Service.GetAiProviderApiKeyAsync("OpenAI")).Should().Be("sk-super-secret");
    }

    [Fact]
    public async Task Clearing_Api_Key_Removes_Stored_Credential_Without_Disabling_Provider()
    {
        await using var fixture = await SettingsFixture.CreateAsync();
        var settings = await fixture.Service.GetAiSettingsAsync();
        await fixture.Service.SaveAiSettingsAsync(
            settings,
            new Dictionary<string, string?> { ["Gemini"] = "gemini-secret" });

        await fixture.Service.ClearAiProviderApiKeyAsync("Gemini");

        (await fixture.Service.GetAiProviderApiKeyAsync("Gemini")).Should().BeNull();
        var reloaded = await fixture.Service.GetAiSettingsAsync();
        reloaded.Providers.Single(x => x.Provider == "Gemini").HasApiKey.Should().BeFalse();
    }

    [Fact]
    public async Task Runtime_Uses_Configured_Priority_And_Stops_Fallback_When_Disabled()
    {
        await using var fixture = await SettingsFixture.CreateAsync();
        var settings = await fixture.Service.GetAiSettingsAsync();
        var configured = settings with
        {
            AutomaticFallback = false,
            Providers = settings.Providers.Select(x => x.Provider switch
            {
                "Gemini" => x with { Enabled = true, Priority = 1 },
                "OpenAI" => x with { Enabled = true, Priority = 2 },
                _ => x
            }).ToArray()
        };
        await fixture.Service.SaveAiSettingsAsync(configured, new Dictionary<string, string?>());

        var first = new CountingProvider("Gemini", false);
        var second = new CountingProvider("OpenAI", true);
        var orchestrator = new SettingsAwareAIOrchestrator(
            new IAIProvider[] { second, first },
            new AIUsageLog(),
            new AIContentProtector(),
            new AIProviderRuntimeSettingsResolver(fixture.Service, fixture.Configuration));

        var result = await orchestrator.ExecuteAsync(new AIRequest("test", UserId: Guid.NewGuid().ToString()));

        result.IsSuccess.Should().BeFalse();
        result.Provider.Should().Be("Gemini");
        first.Calls.Should().Be(2);
        second.Calls.Should().Be(0);
    }

    [Fact]
    public async Task Runtime_Uses_Next_Enabled_Provider_When_Fallback_Is_Enabled()
    {
        await using var fixture = await SettingsFixture.CreateAsync();
        var settings = await fixture.Service.GetAiSettingsAsync();
        var configured = settings with
        {
            AutomaticFallback = true,
            Providers = settings.Providers.Select(x => x.Provider switch
            {
                "Gemini" => x with { Enabled = true, Priority = 1 },
                "OpenAI" => x with { Enabled = true, Priority = 2 },
                _ => x
            }).ToArray()
        };
        await fixture.Service.SaveAiSettingsAsync(configured, new Dictionary<string, string?>());

        var first = new CountingProvider("Gemini", false);
        var second = new CountingProvider("OpenAI", true);
        var orchestrator = new SettingsAwareAIOrchestrator(
            new IAIProvider[] { second, first },
            new AIUsageLog(),
            new AIContentProtector(),
            new AIProviderRuntimeSettingsResolver(fixture.Service, fixture.Configuration));

        var result = await orchestrator.ExecuteAsync(new AIRequest("test", UserId: Guid.NewGuid().ToString()));

        result.IsSuccess.Should().BeTrue();
        result.Provider.Should().Be("OpenAI");
        first.Calls.Should().Be(2);
        second.Calls.Should().Be(1);
    }

    private sealed class CountingProvider(string name, bool succeeds) : IAIProvider
    {
        public string Name => name;
        public int Calls { get; private set; }

        public Task<AIResponse> GenerateAsync(AIRequest request, CancellationToken cancellationToken = default)
        {
            Calls++;
            return Task.FromResult(succeeds
                ? new AIResponse(true, "ok", Name, request.Model, 1, 1, 0)
                : new AIResponse(false, string.Empty, Name, request.Model, 1, 0, 0, "failed"));
        }
    }

    private sealed class TestSecretProtectionService : ISecretProtectionService
    {
        public Task<string> ProtectAsync(string plainText, CancellationToken cancellationToken = default) =>
            Task.FromResult("protected::" + plainText);

        public Task<string> UnprotectAsync(string protectedValue, CancellationToken cancellationToken = default)
        {
            if (!protectedValue.StartsWith("protected::", StringComparison.Ordinal))
                throw new System.Security.Cryptography.CryptographicException("invalid protected value");
            return Task.FromResult(protectedValue[11..]);
        }
    }

    private sealed class SettingsFixture : IAsyncDisposable
    {
        private SettingsFixture(
            SqliteConnection connection,
            AppDbContext context,
            IConfiguration configuration,
            ApplicationSettingsService service)
        {
            Connection = connection;
            Context = context;
            Configuration = configuration;
            Service = service;
        }

        public SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        public IConfiguration Configuration { get; }
        public ApplicationSettingsService Service { get; }

        public static async Task<SettingsFixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
            var context = new AppDbContext(options);
            await context.Database.EnsureCreatedAsync();
            var configuration = new ConfigurationBuilder().AddInMemoryCollection().Build();
            var service = new ApplicationSettingsService(context, configuration, new TestSecretProtectionService());
            return new SettingsFixture(connection, context, configuration, service);
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}
