using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Application.Settings;
using AIWordPressManager.Domain.Entities;
using Microsoft.Extensions.Configuration;

namespace AIWordPressManager.Infrastructure.AI;

public sealed record AIProviderRuntimeConfiguration(
    string Provider,
    bool ApplicationEnabled,
    bool Enabled,
    int Priority,
    bool AutomaticFallback,
    string Model,
    string? ApiKey);

public sealed class AIProviderRuntimeSettingsResolver(
    IApplicationSettingsService settingsService,
    IConfiguration configuration)
{
    private AiSettings? _settings;

    public async Task<AiSettings> GetSettingsAsync(CancellationToken cancellationToken = default) =>
        _settings ??= await settingsService.GetAiSettingsAsync(cancellationToken);

    public async Task<AIProviderRuntimeConfiguration> ResolveAsync(
        string provider,
        string configurationKeyPath,
        string configurationModelPath,
        string defaultModel,
        CancellationToken cancellationToken = default)
    {
        var settings = await GetSettingsAsync(cancellationToken);
        var providerSettings = settings.Providers.FirstOrDefault(x =>
            x.Provider.Equals(provider, StringComparison.OrdinalIgnoreCase));

        var storedKey = await settingsService.GetAiProviderApiKeyAsync(provider, cancellationToken);
        var apiKey = string.IsNullOrWhiteSpace(storedKey)
            ? configuration[configurationKeyPath]
            : storedKey;
        var model = providerSettings is { Model.Length: > 0 }
            ? providerSettings.Model.Trim()
            : configuration[configurationModelPath] ?? defaultModel;

        return new(
            provider,
            settings.Enabled,
            providerSettings?.Enabled ?? true,
            providerSettings?.Priority ?? 20,
            settings.AutomaticFallback,
            model,
            apiKey);
    }
}

public sealed class SettingsAwareAIOrchestrator(
    IEnumerable<IAIProvider> providers,
    IAIUsageLog usageLog,
    IAIContentProtector protector,
    AIProviderRuntimeSettingsResolver runtimeSettings,
    IAccountEntitlementEnforcementService entitlementEnforcement) : IAIOrchestrator
{
    private const string MonthlyRequestUsageMarker = "__billing.ai.request";

    public async Task<AIResponse> ExecuteAsync(AIRequest request, CancellationToken cancellationToken = default)
    {
        if (!Guid.TryParse(request.UserId, out var ownerUserId) || ownerUserId == Guid.Empty)
            return SubscriptionFailure(request, "subscription_account_required", "A signed-in account is required to use AI features.");

        try
        {
            await entitlementEnforcement.RequireBooleanCapabilityAsync(
                ownerUserId,
                EntitlementDefinitionCatalog.AiEnabled,
                cancellationToken);

            var now = DateTime.UtcNow;
            var monthStart = new DateTime(now.Year, now.Month, 1, 0, 0, 0, DateTimeKind.Utc);
            var currentUsage = usageLog.GetRecent(10_000, userId: ownerUserId.ToString("D"))
                .LongCount(x => x.CreatedAtUtc >= monthStart && string.Equals(x.Operation, MonthlyRequestUsageMarker, StringComparison.Ordinal));
            await entitlementEnforcement.RequireAdditionalUsageAsync(
                ownerUserId,
                EntitlementDefinitionCatalog.AiMonthlyRequestsMax,
                currentUsage,
                1,
                cancellationToken);

            usageLog.Record(new(
                now,
                "subscription",
                request.Model,
                MonthlyRequestUsageMarker,
                request.SiteId,
                ownerUserId.ToString("D"),
                0,
                0,
                0,
                true,
                null));
        }
        catch (AccountEntitlementDeniedException ex)
        {
            return SubscriptionFailure(request, ex.Code, ex.Message);
        }

        var identity = request.UserId ?? request.SiteId?.ToString() ?? "anonymous";
        if (!protector.TryConsume(identity, 1, out _))
            return new(false, string.Empty, "quota", request.Model, 0, 0, 0, "Daily AI quota exceeded.");

        var settings = await runtimeSettings.GetSettingsAsync(cancellationToken);
        if (!settings.Enabled)
            return new(false, string.Empty, "none", request.Model, 0, 0, 0, "AI features are disabled in application settings.");

        var providerSettings = settings.Providers
            .ToDictionary(x => x.Provider, StringComparer.OrdinalIgnoreCase);
        var candidates = providers
            .Where(x => !providerSettings.TryGetValue(x.Name, out var configured) || configured.Enabled)
            .OrderBy(x => providerSettings.TryGetValue(x.Name, out var configured) ? configured.Priority : int.MaxValue)
            .ThenBy(x => x.Name, StringComparer.OrdinalIgnoreCase)
            .ToArray();

        if (!settings.AutomaticFallback && candidates.Length > 1)
            candidates = [candidates[0]];

        if (candidates.Length == 0)
            return new(false, string.Empty, "none", request.Model, 0, 0, 0, "No enabled AI provider is configured.");

        var protectedRequest = request with
        {
            Prompt = protector.Redact(request.Prompt),
            SystemPrompt = protector.Redact(request.SystemPrompt ?? string.Empty)
        };

        AIResponse? last = null;
        foreach (var provider in candidates)
        {
            for (var attempt = 1; attempt <= 2; attempt++)
            {
                try
                {
                    last = await provider.GenerateAsync(protectedRequest, cancellationToken);
                    Record(request, last);
                    if (last.IsSuccess) return last;
                }
                catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
                {
                    throw;
                }
                catch (Exception ex)
                {
                    last = new(false, string.Empty, provider.Name, request.Model, 0, 0, 0, SafeExceptionMessage(ex));
                    Record(request, last);
                }

                if (attempt == 1) await Task.Delay(300, cancellationToken);
            }
        }

        return last ?? new(false, string.Empty, "none", request.Model, 0, 0, 0, "No AI provider is configured.");
    }

    private static AIResponse SubscriptionFailure(AIRequest request, string code, string message) =>
        new(false, string.Empty, "subscription", request.Model, 0, 0, 0, $"{code}: {message}");

    private void Record(AIRequest request, AIResponse response) => usageLog.Record(new(
        DateTime.UtcNow,
        response.Provider,
        response.Model,
        request.Operation,
        request.SiteId,
        request.UserId,
        response.InputTokens,
        response.OutputTokens,
        response.EstimatedCost,
        response.IsSuccess,
        response.Error));

    private static string SafeExceptionMessage(Exception exception) => exception switch
    {
        InvalidOperationException => exception.Message,
        _ => "The AI provider request failed unexpectedly."
    };
}

public abstract class SettingsBackedJsonAIProvider(
    HttpClient httpClient,
    AIProviderRuntimeSettingsResolver runtimeSettings) : IAIProvider
{
    protected HttpClient HttpClient { get; } = httpClient;
    protected AIProviderRuntimeSettingsResolver RuntimeSettings { get; } = runtimeSettings;
    public abstract string Name { get; }
    public abstract Task<AIResponse> GenerateAsync(AIRequest request, CancellationToken cancellationToken = default);

    protected static int EstimateTokens(string? value) =>
        string.IsNullOrWhiteSpace(value) ? 0 : Math.Max(1, value.Length / 4);

    protected static StringContent JsonContent(object payload) =>
        new(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json");

    protected static AIResponse DisabledOrMissingKey(
        AIProviderRuntimeConfiguration settings,
        string model,
        bool requiresKey)
    {
        if (!settings.ApplicationEnabled)
            return new(false, string.Empty, settings.Provider, model, 0, 0, 0, "AI features are disabled in application settings.");
        if (!settings.Enabled)
            return new(false, string.Empty, settings.Provider, model, 0, 0, 0, $"{settings.Provider} is disabled in AI provider settings.");
        if (requiresKey && string.IsNullOrWhiteSpace(settings.ApiKey))
            return new(false, string.Empty, settings.Provider, model, 0, 0, 0, $"{settings.Provider} API key is not configured.");
        return new(false, string.Empty, settings.Provider, model, 0, 0, 0, string.Empty);
    }
}

public sealed class SettingsBackedOpenAIProvider(
    HttpClient httpClient,
    AIProviderRuntimeSettingsResolver runtimeSettings)
    : SettingsBackedJsonAIProvider(httpClient, runtimeSettings)
{
    public override string Name => "OpenAI";

    public override async Task<AIResponse> GenerateAsync(AIRequest request, CancellationToken cancellationToken = default)
    {
        var settings = await RuntimeSettings.ResolveAsync(Name, "AI:OpenAI:ApiKey", "AI:OpenAI:Model", "gpt-4.1-mini", cancellationToken);
        var model = request.Model ?? settings.Model;
        var unavailable = DisabledOrMissingKey(settings, model, true);
        if (!string.IsNullOrEmpty(unavailable.Error)) return unavailable;

        using var message = new HttpRequestMessage(HttpMethod.Post, "https://api.openai.com/v1/responses");
        message.Headers.Authorization = new AuthenticationHeaderValue("Bearer", settings.ApiKey);
        message.Content = JsonContent(new
        {
            model,
            instructions = request.SystemPrompt,
            input = request.Prompt,
            max_output_tokens = request.MaxOutputTokens,
            temperature = request.Temperature
        });
        using var response = await HttpClient.SendAsync(message, cancellationToken);
        var body = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode)
            return new(false, string.Empty, Name, model, EstimateTokens(request.Prompt), 0, 0, $"OpenAI returned HTTP {(int)response.StatusCode}.");

        using var json = JsonDocument.Parse(body);
        var content = json.RootElement.TryGetProperty("output_text", out var output)
            ? output.GetString() ?? string.Empty
            : string.Empty;
        return new(true, content, Name, model, EstimateTokens(request.Prompt), EstimateTokens(content), 0);
    }
}

public sealed class SettingsBackedGeminiProvider(
    HttpClient httpClient,
    AIProviderRuntimeSettingsResolver runtimeSettings)
    : SettingsBackedJsonAIProvider(httpClient, runtimeSettings)
{
    public override string Name => "Gemini";

    public override async Task<AIResponse> GenerateAsync(AIRequest request, CancellationToken cancellationToken = default)
    {
        var settings = await RuntimeSettings.ResolveAsync(Name, "AI:Gemini:ApiKey", "AI:Gemini:Model", "gemini-2.5-flash", cancellationToken);
        var model = request.Model ?? settings.Model;
        var unavailable = DisabledOrMissingKey(settings, model, true);
        if (!string.IsNullOrEmpty(unavailable.Error)) return unavailable;

        var url = $"https://generativelanguage.googleapis.com/v1beta/models/{Uri.EscapeDataString(model)}:generateContent?key={Uri.EscapeDataString(settings.ApiKey!)}";
        using var response = await HttpClient.PostAsync(
            url,
            JsonContent(new
            {
                contents = new[] { new { parts = new[] { new { text = $"{request.SystemPrompt}\n\n{request.Prompt}" } } } },
                generationConfig = new { temperature = request.Temperature, maxOutputTokens = request.MaxOutputTokens }
            }),
            cancellationToken);
        var body = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode)
            return new(false, string.Empty, Name, model, EstimateTokens(request.Prompt), 0, 0, $"Gemini returned HTTP {(int)response.StatusCode}.");

        using var json = JsonDocument.Parse(body);
        var content = json.RootElement.GetProperty("candidates")[0]
            .GetProperty("content").GetProperty("parts")[0].GetProperty("text").GetString() ?? string.Empty;
        return new(true, content, Name, model, EstimateTokens(request.Prompt), EstimateTokens(content), 0);
    }
}

public sealed class SettingsBackedPuterProvider(
    HttpClient httpClient,
    AIProviderRuntimeSettingsResolver runtimeSettings,
    IConfiguration configuration)
    : SettingsBackedJsonAIProvider(httpClient, runtimeSettings)
{
    public override string Name => "Puter";

    public override async Task<AIResponse> GenerateAsync(AIRequest request, CancellationToken cancellationToken = default)
    {
        var settings = await RuntimeSettings.ResolveAsync(Name, "AI:Puter:Token", "AI:Puter:Model", "openai/gpt-5-nano", cancellationToken);
        var model = request.Model ?? settings.Model;
        var unavailable = DisabledOrMissingKey(settings, model, false);
        if (!string.IsNullOrEmpty(unavailable.Error)) return unavailable;

        var endpoint = configuration["AI:Puter:Endpoint"];
        if (string.IsNullOrWhiteSpace(endpoint))
            return new(false, string.Empty, Name, model, 0, 0, 0, "Puter endpoint is not configured on the server.");
        if (!Uri.TryCreate(endpoint, UriKind.Absolute, out var endpointUri) || endpointUri.Scheme != Uri.UriSchemeHttps)
            return new(false, string.Empty, Name, model, 0, 0, 0, "Puter endpoint must be an absolute HTTPS URL.");

        using var message = new HttpRequestMessage(HttpMethod.Post, endpointUri);
        if (!string.IsNullOrWhiteSpace(settings.ApiKey))
            message.Headers.Authorization = new AuthenticationHeaderValue("Bearer", settings.ApiKey);
        message.Content = JsonContent(new
        {
            prompt = request.Prompt,
            system = request.SystemPrompt,
            model,
            temperature = request.Temperature,
            max_tokens = request.MaxOutputTokens
        });
        using var response = await HttpClient.SendAsync(message, cancellationToken);
        var body = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode)
            return new(false, string.Empty, Name, model, EstimateTokens(request.Prompt), 0, 0, $"Puter returned HTTP {(int)response.StatusCode}.");
        return new(true, body, Name, model, EstimateTokens(request.Prompt), EstimateTokens(body), 0);
    }
}
