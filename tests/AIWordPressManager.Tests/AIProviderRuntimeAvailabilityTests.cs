using System.Security.Claims;
using AIWordPressManager.Application.Settings;
using AIWordPressManager.Infrastructure.AI;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;

namespace AIWordPressManager.Tests;

public sealed class AIProviderRuntimeAvailabilityTests
{
    [Fact]
    public async Task Administration_boundary_never_reports_unsupported_provider_as_enabled()
    {
        var runtime = new RecordingSettingsService(SettingsWithEveryProviderEnabled());
        var service = CreateService(runtime);

        var loaded = await service.GetAsync();

        loaded.Providers.Where(x => AIProviderRuntimeCatalog.IsAvailable(x.Provider))
            .Should().OnlyContain(x => x.Enabled);
        loaded.Providers.Where(x => !AIProviderRuntimeCatalog.IsAvailable(x.Provider))
            .Should().OnlyContain(x => !x.Enabled);
        loaded.Providers.Single(x => x.Provider == "Groq").Model.Should().Be("groq-model");
        loaded.Providers.Single(x => x.Provider == "Ollama").Priority.Should().Be(6);
    }

    [Fact]
    public async Task Administration_boundary_never_persists_unsupported_provider_as_enabled()
    {
        var settings = SettingsWithEveryProviderEnabled();
        var runtime = new RecordingSettingsService(settings);
        var service = CreateService(runtime);
        var keys = new Dictionary<string, string?>
        {
            ["OpenAI"] = "sk-new",
            ["Groq"] = "future-groq-key"
        };

        await service.SaveAsync(settings, keys);

        runtime.LastSavedSettings.Should().NotBeNull();
        runtime.LastSavedSettings!.Providers.Single(x => x.Provider == "OpenAI").Enabled.Should().BeTrue();
        runtime.LastSavedSettings.Providers.Single(x => x.Provider == "Gemini").Enabled.Should().BeTrue();
        runtime.LastSavedSettings.Providers.Single(x => x.Provider == "Puter").Enabled.Should().BeTrue();
        runtime.LastSavedSettings.Providers.Single(x => x.Provider == "Groq").Enabled.Should().BeFalse();
        runtime.LastSavedSettings.Providers.Single(x => x.Provider == "OpenRouter").Enabled.Should().BeFalse();
        runtime.LastSavedSettings.Providers.Single(x => x.Provider == "Ollama").Enabled.Should().BeFalse();
        runtime.LastSavedSettings.Providers.Single(x => x.Provider == "Groq").Model.Should().Be("groq-model");
        runtime.LastPlainApiKeys.Should().BeSameAs(keys, "future configuration storage is real even though execution is unavailable");
    }

    [Fact]
    public void Runtime_catalog_matches_the_provider_adapters_registered_in_the_server_build()
    {
        AIProviderRuntimeCatalog.AvailableProviders.Should().BeEquivalentTo(["OpenAI", "Gemini", "Puter"]);

        var dependencyInjection = ReadRepositoryFile("src/AIWordPressManager.Infrastructure/DependencyInjection.cs");
        foreach (var provider in AIProviderRuntimeCatalog.AvailableProviders)
        {
            dependencyInjection.Should().Contain($"SettingsBacked{provider}Provider");
        }

        foreach (var unsupported in new[] { "Groq", "OpenRouter", "Ollama" })
        {
            dependencyInjection.Should().NotContain($"SettingsBacked{unsupported}Provider");
        }
    }

    [Fact]
    public void Provider_settings_page_exposes_unavailable_state_instead_of_unsupported_enable_control()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AIProviderSettings.razor");

        page.Should().Contain("@if (AIProviderRuntimeCatalog.IsAvailable(provider.Name))");
        page.Should().Contain("data-runtime-unavailable=\"@provider.Name\"");
        page.Should().Contain("This provider cannot be enabled until a real runtime adapter is installed in the server build.");
        page.Should().Contain("This provider configuration can be stored for future use, but it cannot execute or enter runtime ordering in the current server build.");
    }

    private static AIProviderSettingsAdministrationService CreateService(IApplicationSettingsService runtime)
    {
        var claims = new List<Claim>
        {
            new(ClaimTypes.NameIdentifier, Guid.NewGuid().ToString("D")),
            new(ClaimTypes.Name, "ai-provider-runtime-test"),
            new(ClaimTypes.Role, "User"),
            new(ApplicationPermissionCatalog.ClaimType, ApplicationPermissionCatalog.SettingsManage)
        };
        var context = new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(claims, "Test"))
        };
        return new AIProviderSettingsAdministrationService(
            runtime,
            new CurrentUserContext(new IsolatedHttpContextAccessor(context)));
    }

    private static AiSettings SettingsWithEveryProviderEnabled() => new(
        true,
        true,
        [
            new AiProviderSettings("OpenAI", true, 1, "gpt-test", string.Empty, true),
            new AiProviderSettings("Gemini", true, 2, "gemini-test", string.Empty, true),
            new AiProviderSettings("Puter", true, 3, "puter-test", string.Empty, false),
            new AiProviderSettings("Groq", true, 4, "groq-model", string.Empty, true),
            new AiProviderSettings("OpenRouter", true, 5, "openrouter-model", string.Empty, true),
            new AiProviderSettings("Ollama", true, 6, "ollama-model", string.Empty, false)
        ]);

    private static string ReadRepositoryFile(string relativePath)
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "AIWordPressManager.Web.sln")))
                return File.ReadAllText(Path.Combine(current.FullName, relativePath));
            current = current.Parent;
        }

        throw new DirectoryNotFoundException("Could not locate AIMWWeb repository root from the test output directory.");
    }

    private sealed class IsolatedHttpContextAccessor(HttpContext? context) : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; } = context;
    }

    private sealed class RecordingSettingsService(AiSettings settings) : IApplicationSettingsService
    {
        public AiSettings Settings { get; private set; } = settings;
        public AiSettings? LastSavedSettings { get; private set; }
        public IReadOnlyDictionary<string, string?>? LastPlainApiKeys { get; private set; }

        public Task<AiSettings> GetAiSettingsAsync(CancellationToken cancellationToken = default) => Task.FromResult(Settings);

        public Task SaveAiSettingsAsync(AiSettings settings, IReadOnlyDictionary<string, string?> plainApiKeys, CancellationToken cancellationToken = default)
        {
            Settings = settings;
            LastSavedSettings = settings;
            LastPlainApiKeys = plainApiKeys;
            return Task.CompletedTask;
        }

        public Task ClearAiProviderApiKeyAsync(string provider, CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task<string?> GetAiProviderApiKeyAsync(string provider, CancellationToken cancellationToken = default) => Task.FromResult<string?>(null);
        public Task<SynchronizationSettings> GetSynchronizationSettingsAsync(CancellationToken cancellationToken = default) => Task.FromResult(new SynchronizationSettings(60, true, true));
        public Task SaveSynchronizationSettingsAsync(SynchronizationSettings settings, CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task<PerformanceSettings> GetPerformanceSettingsAsync(CancellationToken cancellationToken = default) => Task.FromResult(new PerformanceSettings(true, 80, 72, 5, true));
        public Task SavePerformanceSettingsAsync(PerformanceSettings settings, CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task<JobReliabilitySettings> GetJobReliabilitySettingsAsync(CancellationToken cancellationToken = default) => Task.FromResult(new JobReliabilitySettings(true, 3, 15, true));
        public Task SaveJobReliabilitySettingsAsync(JobReliabilitySettings settings, CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task<AiAutomationSettings> GetAiAutomationSettingsAsync(CancellationToken cancellationToken = default) => Task.FromResult(new AiAutomationSettings(true, "Ask", false, true, true, true, 3));
        public Task SaveAiAutomationSettingsAsync(AiAutomationSettings settings, CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task<DestructiveOperationSettings> GetDestructiveOperationSettingsAsync(CancellationToken cancellationToken = default) => Task.FromResult(new DestructiveOperationSettings(true, false, false, true));
        public Task SaveDestructiveOperationSettingsAsync(DestructiveOperationSettings settings, CancellationToken cancellationToken = default) => Task.CompletedTask;
    }
}
