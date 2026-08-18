using System.Security.Claims;
using AIWordPressManager.Application.Settings;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;

namespace AIWordPressManager.Tests;

public sealed class AIProviderSettingsAdministrationServiceTests
{
    [Fact]
    public async Task User_without_SettingsManage_cannot_read_or_mutate_provider_settings()
    {
        var runtime = new RecordingSettingsService();
        var service = CreateService(runtime, "User");
        var settings = SampleSettings();

        await FluentActions.Invoking(() => service.GetAsync()).Should().ThrowAsync<UnauthorizedAccessException>();
        await FluentActions.Invoking(() => service.SaveAsync(settings, new Dictionary<string, string?>())).Should().ThrowAsync<UnauthorizedAccessException>();
        await FluentActions.Invoking(() => service.ClearApiKeyAsync("OpenAI")).Should().ThrowAsync<UnauthorizedAccessException>();

        runtime.GetAiSettingsCalls.Should().Be(0);
        runtime.SaveAiSettingsCalls.Should().Be(0);
        runtime.ClearAiProviderApiKeyCalls.Should().Be(0);
    }

    [Fact]
    public async Task SettingsManage_claim_allows_read_save_and_key_removal_without_Administrator_role()
    {
        var runtime = new RecordingSettingsService();
        var service = CreateService(runtime, "User", ApplicationPermissionCatalog.SettingsManage);
        var settings = SampleSettings();
        var keys = new Dictionary<string, string?> { ["OpenAI"] = "secret" };

        (await service.GetAsync()).Should().BeSameAs(runtime.Settings);
        await service.SaveAsync(settings, keys);
        await service.ClearApiKeyAsync("OpenAI");

        runtime.GetAiSettingsCalls.Should().Be(1);
        runtime.SaveAiSettingsCalls.Should().Be(1);
        runtime.ClearAiProviderApiKeyCalls.Should().Be(1);
        runtime.LastSavedSettings.Should().BeSameAs(settings);
        runtime.LastPlainApiKeys.Should().BeSameAs(keys);
        runtime.LastClearedProvider.Should().Be("OpenAI");
    }

    [Fact]
    public async Task Legacy_Administrator_role_keeps_SettingsManage_access()
    {
        var runtime = new RecordingSettingsService();
        var service = CreateService(runtime, "Administrator");

        await service.GetAsync();

        runtime.GetAiSettingsCalls.Should().Be(1);
    }

    [Fact]
    public async Task Background_owner_identity_cannot_acquire_SettingsManage()
    {
        var runtime = new RecordingSettingsService();
        var accessor = new IsolatedHttpContextAccessor(null);
        var service = new AIProviderSettingsAdministrationService(runtime, new CurrentUserContext(accessor));
        using var lease = BackgroundExecutionIdentity.Push(Guid.NewGuid());

        await FluentActions.Invoking(() => service.GetAsync()).Should().ThrowAsync<UnauthorizedAccessException>();

        runtime.GetAiSettingsCalls.Should().Be(0);
    }

    private static AIProviderSettingsAdministrationService CreateService(
        IApplicationSettingsService runtime,
        string role,
        params string[] permissions)
    {
        var claims = new List<Claim>
        {
            new(ClaimTypes.NameIdentifier, Guid.NewGuid().ToString()),
            new(ClaimTypes.Name, "settings-test"),
            new(ClaimTypes.Role, role)
        };
        claims.AddRange(permissions.Select(permission => new Claim(ApplicationPermissionCatalog.ClaimType, permission)));
        var context = new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(claims, "Test"))
        };
        return new AIProviderSettingsAdministrationService(runtime, new CurrentUserContext(new IsolatedHttpContextAccessor(context)));
    }

    private static AiSettings SampleSettings() => new(
        true,
        true,
        [new AiProviderSettings("OpenAI", true, 1, "gpt-test", string.Empty, true)]);

    private sealed class IsolatedHttpContextAccessor(HttpContext? context) : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; } = context;
    }

    private sealed class RecordingSettingsService : IApplicationSettingsService
    {
        public AiSettings Settings { get; } = SampleSettings();
        public int GetAiSettingsCalls { get; private set; }
        public int SaveAiSettingsCalls { get; private set; }
        public int ClearAiProviderApiKeyCalls { get; private set; }
        public AiSettings? LastSavedSettings { get; private set; }
        public IReadOnlyDictionary<string, string?>? LastPlainApiKeys { get; private set; }
        public string? LastClearedProvider { get; private set; }

        public Task<AiSettings> GetAiSettingsAsync(CancellationToken cancellationToken = default)
        {
            GetAiSettingsCalls++;
            return Task.FromResult(Settings);
        }

        public Task SaveAiSettingsAsync(AiSettings settings, IReadOnlyDictionary<string, string?> plainApiKeys, CancellationToken cancellationToken = default)
        {
            SaveAiSettingsCalls++;
            LastSavedSettings = settings;
            LastPlainApiKeys = plainApiKeys;
            return Task.CompletedTask;
        }

        public Task ClearAiProviderApiKeyAsync(string provider, CancellationToken cancellationToken = default)
        {
            ClearAiProviderApiKeyCalls++;
            LastClearedProvider = provider;
            return Task.CompletedTask;
        }

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