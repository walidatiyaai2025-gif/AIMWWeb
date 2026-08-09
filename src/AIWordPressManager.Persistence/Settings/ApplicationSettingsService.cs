using System.Security.Cryptography;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Settings;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Configuration;

namespace AIWordPressManager.Persistence.Settings;

public sealed class ApplicationSettingsService(
    AppDbContext dbContext,
    IConfiguration configuration,
    ISecretProtectionService secretProtectionService) : IApplicationSettingsService
{
    private static readonly string[] ProviderNames = ["Puter", "Ollama", "Gemini", "Groq", "OpenRouter", "OpenAI"];
    private const string AiPromptRegistryKey = "AI.PromptRegistry.V1";
    private const int MaxPromptTextLength = 8000;
    private const int MaxPromptHistory = 50;
    private static readonly JsonSerializerOptions PromptJsonOptions = new(JsonSerializerDefaults.Web);

    public async Task<SynchronizationSettings> GetSynchronizationSettingsAsync(CancellationToken cancellationToken = default)
    {
        var keys = new[] { "Synchronization.IntervalMinutes", "Synchronization.RunOnStartup", "Synchronization.OfflineFirst" };
        var values = await dbContext.ApplicationSettings.AsNoTracking().Where(x => keys.Contains(x.Key)).ToDictionaryAsync(x => x.Key, x => x.Value, cancellationToken);
        return new(
            Math.Clamp(ParseInt(values, keys[0], configuration.GetValue("Synchronization:IntervalMinutes", 60)), 5, 1440),
            ParseBool(values, keys[1], configuration.GetValue("Synchronization:RunOnStartup", true)),
            ParseBool(values, keys[2], configuration.GetValue("Synchronization:OfflineFirst", true)));
    }

    public async Task SaveSynchronizationSettingsAsync(SynchronizationSettings settings, CancellationToken cancellationToken = default)
    {
        await UpsertAsync("Synchronization.IntervalMinutes", Math.Clamp(settings.IntervalMinutes, 5, 1440).ToString(), cancellationToken);
        await UpsertAsync("Synchronization.RunOnStartup", settings.RunOnStartup.ToString(), cancellationToken);
        await UpsertAsync("Synchronization.OfflineFirst", settings.OfflineFirst.ToString(), cancellationToken);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task<AiSettings> GetAiSettingsAsync(CancellationToken cancellationToken = default)
    {
        var prefixes = ProviderNames.SelectMany(name => new[]
        {
            $"AI.{name}.Enabled", $"AI.{name}.Priority", $"AI.{name}.Model", $"AI.{name}.ProtectedApiKey"
        }).Concat(["AI.Enabled", "AI.AutomaticFallback", "AI.Model", "AI.ProtectedApiKey"]).ToArray();

        var values = await dbContext.ApplicationSettings.AsNoTracking()
            .Where(x => prefixes.Contains(x.Key))
            .ToDictionaryAsync(x => x.Key, x => x.Value, cancellationToken);

        var defaults = new Dictionary<string, (int Priority, string Model)>(StringComparer.OrdinalIgnoreCase)
        {
            ["Puter"] = (1, "openai/gpt-5-nano"),
            ["Ollama"] = (2, "qwen3:4b"),
            ["Gemini"] = (3, "gemini-2.5-flash"),
            ["Groq"] = (4, "llama-3.3-70b-versatile"),
            ["OpenRouter"] = (5, "openrouter/free"),
            ["OpenAI"] = (6, values.TryGetValue("AI.Model", out var oldModel) ? oldModel : "gpt-5.1")
        };

        var providers = new List<AiProviderSettings>();
        foreach (var name in ProviderNames)
        {
            var protectedKey = values.TryGetValue($"AI.{name}.ProtectedApiKey", out var key) ? key : string.Empty;
            if (name == "OpenAI" && string.IsNullOrWhiteSpace(protectedKey) && values.TryGetValue("AI.ProtectedApiKey", out var legacyKey))
                protectedKey = legacyKey;

            providers.Add(new AiProviderSettings(
                name,
                ParseBool(values, $"AI.{name}.Enabled", name == "Puter" || name == "Ollama" || name == "Gemini"),
                Math.Clamp(ParseInt(values, $"AI.{name}.Priority", defaults[name].Priority), 1, 20),
                values.TryGetValue($"AI.{name}.Model", out var model) && !string.IsNullOrWhiteSpace(model) ? model : defaults[name].Model,
                protectedKey,
                name is "Ollama" || !string.IsNullOrWhiteSpace(protectedKey)));
        }

        return new AiSettings(
            ParseBool(values, "AI.Enabled", true),
            ParseBool(values, "AI.AutomaticFallback", true),
            providers.OrderBy(x => x.Priority).ToArray());
    }

    public async Task SaveAiSettingsAsync(
        AiSettings settings,
        IReadOnlyDictionary<string, string?> plainApiKeys,
        CancellationToken cancellationToken = default)
    {
        await UpsertAsync("AI.Enabled", settings.Enabled.ToString(), cancellationToken);
        await UpsertAsync("AI.AutomaticFallback", settings.AutomaticFallback.ToString(), cancellationToken);

        foreach (var provider in settings.Providers)
        {
            var safeName = ProviderNames.FirstOrDefault(x => x.Equals(provider.Provider, StringComparison.OrdinalIgnoreCase));
            if (safeName is null) continue;
            await UpsertAsync($"AI.{safeName}.Enabled", provider.Enabled.ToString(), cancellationToken);
            await UpsertAsync($"AI.{safeName}.Priority", Math.Clamp(provider.Priority, 1, 20).ToString(), cancellationToken);
            await UpsertAsync($"AI.{safeName}.Model", provider.Model.Trim(), cancellationToken);
            if (plainApiKeys.TryGetValue(safeName, out var plainKey) && !string.IsNullOrWhiteSpace(plainKey))
            {
                var protectedKey = await secretProtectionService.ProtectAsync(plainKey.Trim(), cancellationToken);
                await UpsertAsync($"AI.{safeName}.ProtectedApiKey", protectedKey, cancellationToken);
            }
        }
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task<string?> GetAiProviderApiKeyAsync(string provider, CancellationToken cancellationToken = default)
    {
        var safeName = RequireProviderName(provider);
        var keys = safeName == "OpenAI"
            ? new[] { $"AI.{safeName}.ProtectedApiKey", "AI.ProtectedApiKey" }
            : new[] { $"AI.{safeName}.ProtectedApiKey" };
        var values = await dbContext.ApplicationSettings.AsNoTracking()
            .Where(x => keys.Contains(x.Key))
            .ToDictionaryAsync(x => x.Key, x => x.Value, cancellationToken);

        var protectedValue = values.TryGetValue(keys[0], out var current) ? current : string.Empty;
        if (string.IsNullOrWhiteSpace(protectedValue) && keys.Length > 1 && values.TryGetValue(keys[1], out var legacy))
            protectedValue = legacy;
        if (string.IsNullOrWhiteSpace(protectedValue)) return null;

        try
        {
            return await secretProtectionService.UnprotectAsync(protectedValue, cancellationToken);
        }
        catch (CryptographicException ex)
        {
            throw new InvalidOperationException(
                $"The stored {safeName} credential could not be decrypted. Re-enter the key in AI provider settings.", ex);
        }
    }

    public async Task ClearAiProviderApiKeyAsync(string provider, CancellationToken cancellationToken = default)
    {
        var safeName = RequireProviderName(provider);
        var keys = safeName == "OpenAI"
            ? new[] { $"AI.{safeName}.ProtectedApiKey", "AI.ProtectedApiKey" }
            : new[] { $"AI.{safeName}.ProtectedApiKey" };
        var rows = await dbContext.ApplicationSettings.Where(x => keys.Contains(x.Key)).ToListAsync(cancellationToken);
        if (rows.Count == 0) return;
        dbContext.ApplicationSettings.RemoveRange(rows);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task<IReadOnlyList<AiPromptTemplateSettings>> GetAiPromptTemplatesAsync(CancellationToken cancellationToken = default)
    {
        var registry = await LoadAiPromptRegistryAsync(cancellationToken);
        var result = new List<AiPromptTemplateSettings>(registry.Templates.Count);
        foreach (var template in registry.Templates.OrderBy(x => x.Key, StringComparer.OrdinalIgnoreCase))
        {
            var latest = template.Versions.OrderByDescending(x => x.Version).FirstOrDefault();
            if (latest is null) continue;
            result.Add(ToCurrent(template.Key, latest));
        }
        return result;
    }

    public async Task<IReadOnlyList<AiPromptTemplateVersionSettings>> GetAiPromptTemplateHistoryAsync(
        string key,
        CancellationToken cancellationToken = default)
    {
        var normalizedKey = NormalizePromptKey(key);
        var registry = await LoadAiPromptRegistryAsync(cancellationToken);
        var template = registry.Templates.FirstOrDefault(x => x.Key.Equals(normalizedKey, StringComparison.OrdinalIgnoreCase));
        if (template is null) return [];
        return template.Versions
            .OrderByDescending(x => x.Version)
            .Select(x => new AiPromptTemplateVersionSettings(
                normalizedKey,
                x.Version,
                x.EnglishText,
                x.ArabicText,
                x.IsEnabled,
                x.CreatedAtUtc,
                x.UpdatedBy))
            .ToArray();
    }

    public async Task<AiPromptTemplateSettings> SaveAiPromptTemplateAsync(
        string key,
        string englishText,
        string arabicText,
        bool isEnabled,
        string updatedBy,
        CancellationToken cancellationToken = default)
    {
        var normalizedKey = NormalizePromptKey(key);
        var safeEnglish = NormalizePromptText(englishText, nameof(englishText));
        var safeArabic = NormalizePromptText(arabicText, nameof(arabicText));
        var safeActor = NormalizePromptActor(updatedBy);
        var registry = await LoadAiPromptRegistryAsync(cancellationToken);
        var template = registry.Templates.FirstOrDefault(x => x.Key.Equals(normalizedKey, StringComparison.OrdinalIgnoreCase));
        if (template is null)
        {
            template = new StoredPromptTemplate { Key = normalizedKey };
            registry.Templates.Add(template);
        }

        var nextVersion = template.Versions.Count == 0 ? 1 : template.Versions.Max(x => x.Version) + 1;
        var version = new StoredPromptTemplateVersion
        {
            Version = nextVersion,
            EnglishText = safeEnglish,
            ArabicText = safeArabic,
            IsEnabled = isEnabled,
            CreatedAtUtc = DateTime.UtcNow,
            UpdatedBy = safeActor
        };
        template.Versions.Add(version);
        TrimPromptHistory(template);
        await SaveAiPromptRegistryAsync(registry, cancellationToken);
        return ToCurrent(normalizedKey, version);
    }

    public async Task<AiPromptTemplateSettings> RestoreAiPromptTemplateVersionAsync(
        string key,
        int version,
        string updatedBy,
        CancellationToken cancellationToken = default)
    {
        var normalizedKey = NormalizePromptKey(key);
        if (version < 1) throw new ArgumentOutOfRangeException(nameof(version), "Prompt version must be greater than zero.");
        var safeActor = NormalizePromptActor(updatedBy);
        var registry = await LoadAiPromptRegistryAsync(cancellationToken);
        var template = registry.Templates.FirstOrDefault(x => x.Key.Equals(normalizedKey, StringComparison.OrdinalIgnoreCase))
            ?? throw new InvalidOperationException($"Prompt template '{normalizedKey}' has no saved versions.");
        var source = template.Versions.FirstOrDefault(x => x.Version == version)
            ?? throw new InvalidOperationException($"Prompt template '{normalizedKey}' version {version} was not found.");

        var nextVersion = template.Versions.Max(x => x.Version) + 1;
        var restored = new StoredPromptTemplateVersion
        {
            Version = nextVersion,
            EnglishText = source.EnglishText,
            ArabicText = source.ArabicText,
            IsEnabled = source.IsEnabled,
            CreatedAtUtc = DateTime.UtcNow,
            UpdatedBy = safeActor
        };
        template.Versions.Add(restored);
        TrimPromptHistory(template);
        await SaveAiPromptRegistryAsync(registry, cancellationToken);
        return ToCurrent(normalizedKey, restored);
    }

    public async Task<PerformanceSettings> GetPerformanceSettingsAsync(CancellationToken cancellationToken = default)
    {
        var keys = new[] { "Performance.MemoryCoolingEnabled", "Performance.CoolingThresholdPercent", "Performance.ResumeThresholdPercent", "Performance.CheckIntervalSeconds", "Performance.KillChildProcessesOnExit" };
        var values = await dbContext.ApplicationSettings.AsNoTracking().Where(x => keys.Contains(x.Key)).ToDictionaryAsync(x => x.Key, x => x.Value, cancellationToken);
        var start = Math.Clamp(ParseInt(values, keys[1], 80), 50, 98);
        return new(ParseBool(values, keys[0], true), start, Math.Clamp(ParseInt(values, keys[2], 72), 40, start - 1), Math.Clamp(ParseInt(values, keys[3], 5), 1, 60), ParseBool(values, keys[4], true));
    }

    public async Task SavePerformanceSettingsAsync(PerformanceSettings settings, CancellationToken cancellationToken = default)
    {
        var start = Math.Clamp(settings.CoolingThresholdPercent, 50, 98);
        var resume = Math.Clamp(settings.ResumeThresholdPercent, 40, start - 1);
        await UpsertAsync("Performance.MemoryCoolingEnabled", settings.EnableMemoryCooling.ToString(), cancellationToken);
        await UpsertAsync("Performance.CoolingThresholdPercent", start.ToString(), cancellationToken);
        await UpsertAsync("Performance.ResumeThresholdPercent", resume.ToString(), cancellationToken);
        await UpsertAsync("Performance.CheckIntervalSeconds", Math.Clamp(settings.CheckIntervalSeconds, 1, 60).ToString(), cancellationToken);
        await UpsertAsync("Performance.KillChildProcessesOnExit", settings.KillChildProcessesOnExit.ToString(), cancellationToken);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task<JobReliabilitySettings> GetJobReliabilitySettingsAsync(CancellationToken cancellationToken = default)
    {
        var keys = new[]
        {
            "Jobs.PauseAfterFailures", "Jobs.ConsecutiveFailuresBeforePause",
            "Jobs.FailurePauseMinutes", "Jobs.AutoResumeAfterPause"
        };
        var values = await dbContext.ApplicationSettings.AsNoTracking()
            .Where(x => keys.Contains(x.Key))
            .ToDictionaryAsync(x => x.Key, x => x.Value, cancellationToken);
        return new(
            ParseBool(values, keys[0], true),
            Math.Clamp(ParseInt(values, keys[1], 3), 1, 20),
            Math.Clamp(ParseInt(values, keys[2], 15), 1, 1440),
            ParseBool(values, keys[3], true));
    }

    public async Task SaveJobReliabilitySettingsAsync(JobReliabilitySettings settings, CancellationToken cancellationToken = default)
    {
        await UpsertAsync("Jobs.PauseAfterFailures", settings.PauseAfterFailures.ToString(), cancellationToken);
        await UpsertAsync("Jobs.ConsecutiveFailuresBeforePause", Math.Clamp(settings.ConsecutiveFailuresBeforePause, 1, 20).ToString(), cancellationToken);
        await UpsertAsync("Jobs.FailurePauseMinutes", Math.Clamp(settings.FailurePauseMinutes, 1, 1440).ToString(), cancellationToken);
        await UpsertAsync("Jobs.AutoResumeAfterPause", settings.AutoResumeAfterPause.ToString(), cancellationToken);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task<AiAutomationSettings> GetAiAutomationSettingsAsync(CancellationToken cancellationToken = default)
    {
        var keys = new[]
        {
            "Automation.EnableAiErrorDiagnosis", "Automation.ErrorDecisionMode",
            "Automation.AutoExecuteLowRiskAiActions", "Automation.AutoRejectHighRiskAiActions",
            "Automation.CaptureBeforeAfterEvidence", "Automation.RequireVerifiedExecutionResult",
            "Automation.MinimumSplashSeconds"
        };
        var values = await dbContext.ApplicationSettings.AsNoTracking().Where(x => keys.Contains(x.Key))
            .ToDictionaryAsync(x => x.Key, x => x.Value, cancellationToken);
        var mode = values.TryGetValue(keys[1], out var storedMode) ? storedMode : "Ask";
        if (mode is not ("Ask" or "AutoLowRisk" or "ManualOnly")) mode = "Ask";
        return new(
            ParseBool(values, keys[0], true), mode,
            ParseBool(values, keys[2], false), ParseBool(values, keys[3], true),
            ParseBool(values, keys[4], true), ParseBool(values, keys[5], true),
            Math.Clamp(ParseInt(values, keys[6], 3), 3, 30));
    }

    public async Task SaveAiAutomationSettingsAsync(AiAutomationSettings settings, CancellationToken cancellationToken = default)
    {
        var autoExecute = settings.AutoExecuteLowRiskAiActions;
        var mode = autoExecute ? "AutoLowRisk" : settings.ErrorDecisionMode is "AutoLowRisk" or "ManualOnly" ? settings.ErrorDecisionMode : "Ask";
        var rejectHighRisk = autoExecute || settings.AutoRejectHighRiskAiActions;
        var captureEvidence = autoExecute || settings.CaptureBeforeAfterEvidence;
        var requireVerification = autoExecute || settings.RequireVerifiedExecutionResult;

        await UpsertAsync("Automation.EnableAiErrorDiagnosis", settings.EnableAiErrorDiagnosis.ToString(), cancellationToken);
        await UpsertAsync("Automation.ErrorDecisionMode", mode, cancellationToken);
        await UpsertAsync("Automation.AutoExecuteLowRiskAiActions", autoExecute.ToString(), cancellationToken);
        await UpsertAsync("Automation.AutoRejectHighRiskAiActions", rejectHighRisk.ToString(), cancellationToken);
        await UpsertAsync("Automation.CaptureBeforeAfterEvidence", captureEvidence.ToString(), cancellationToken);
        await UpsertAsync("Automation.RequireVerifiedExecutionResult", requireVerification.ToString(), cancellationToken);
        await UpsertAsync("Automation.MinimumSplashSeconds", Math.Clamp(settings.MinimumSplashSeconds, 3, 30).ToString(), cancellationToken);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task<DestructiveOperationSettings> GetDestructiveOperationSettingsAsync(CancellationToken cancellationToken = default)
    {
        var keys = new[]
        {
            "Destructive.EnableContentTrash", "Destructive.EnablePermanentContentDelete",
            "Destructive.EnablePermanentMediaDelete", "Destructive.RequireBackupBeforePermanentDelete"
        };
        var values = await dbContext.ApplicationSettings.AsNoTracking().Where(x => keys.Contains(x.Key)).ToDictionaryAsync(x => x.Key, x => x.Value, cancellationToken);
        return new(
            ParseBool(values, keys[0], true), ParseBool(values, keys[1], false),
            ParseBool(values, keys[2], false), ParseBool(values, keys[3], true));
    }

    public async Task SaveDestructiveOperationSettingsAsync(DestructiveOperationSettings settings, CancellationToken cancellationToken = default)
    {
        await UpsertAsync("Destructive.EnableContentTrash", settings.EnableContentTrash.ToString(), cancellationToken);
        await UpsertAsync("Destructive.EnablePermanentContentDelete", settings.EnablePermanentContentDelete.ToString(), cancellationToken);
        await UpsertAsync("Destructive.EnablePermanentMediaDelete", settings.EnablePermanentMediaDelete.ToString(), cancellationToken);
        await UpsertAsync("Destructive.RequireBackupBeforePermanentDelete", settings.RequireBackupBeforePermanentDelete.ToString(), cancellationToken);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    private async Task<StoredPromptRegistry> LoadAiPromptRegistryAsync(CancellationToken cancellationToken)
    {
        var json = await dbContext.ApplicationSettings.AsNoTracking()
            .Where(x => x.Key == AiPromptRegistryKey)
            .Select(x => x.Value)
            .SingleOrDefaultAsync(cancellationToken);
        if (string.IsNullOrWhiteSpace(json)) return new StoredPromptRegistry();

        try
        {
            return JsonSerializer.Deserialize<StoredPromptRegistry>(json, PromptJsonOptions) ?? new StoredPromptRegistry();
        }
        catch (JsonException ex)
        {
            throw new InvalidOperationException("The saved AI prompt registry is invalid and could not be loaded.", ex);
        }
    }

    private async Task SaveAiPromptRegistryAsync(StoredPromptRegistry registry, CancellationToken cancellationToken)
    {
        var json = JsonSerializer.Serialize(registry, PromptJsonOptions);
        await UpsertAsync(AiPromptRegistryKey, json, cancellationToken);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    private async Task UpsertAsync(string key, string value, CancellationToken cancellationToken)
    {
        var row = await dbContext.ApplicationSettings.SingleOrDefaultAsync(x => x.Key == key, cancellationToken);
        if (row is null) dbContext.ApplicationSettings.Add(new ApplicationSetting(key, value, DateTime.UtcNow));
        else row.SetValue(key, value, DateTime.UtcNow);
    }

    private static AiPromptTemplateSettings ToCurrent(string key, StoredPromptTemplateVersion version) =>
        new(key, version.EnglishText, version.ArabicText, version.IsEnabled, version.Version, version.CreatedAtUtc, version.UpdatedBy);

    private static void TrimPromptHistory(StoredPromptTemplate template)
    {
        if (template.Versions.Count <= MaxPromptHistory) return;
        template.Versions = template.Versions
            .OrderByDescending(x => x.Version)
            .Take(MaxPromptHistory)
            .OrderBy(x => x.Version)
            .ToList();
    }

    private static string NormalizePromptKey(string key)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(key);
        var normalized = key.Trim().ToLowerInvariant();
        if (normalized.Length > 80)
            throw new ArgumentOutOfRangeException(nameof(key), "Prompt key cannot exceed 80 characters.");
        if (normalized.Any(ch => !(char.IsAsciiLetterOrDigit(ch) || ch is '-' or '_' or '.')))
            throw new ArgumentException("Prompt key may contain only letters, digits, '.', '_' and '-'.", nameof(key));
        return normalized;
    }

    private static string NormalizePromptText(string value, string parameterName)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(value, parameterName);
        var normalized = value.Trim();
        if (normalized.Length > MaxPromptTextLength)
            throw new ArgumentOutOfRangeException(parameterName, $"Prompt text cannot exceed {MaxPromptTextLength} characters.");
        return normalized;
    }

    private static string NormalizePromptActor(string updatedBy)
    {
        var actor = string.IsNullOrWhiteSpace(updatedBy) ? "system" : updatedBy.Trim();
        return actor.Length <= 128 ? actor : actor[..128];
    }

    private static string RequireProviderName(string provider) =>
        ProviderNames.FirstOrDefault(x => x.Equals(provider?.Trim(), StringComparison.OrdinalIgnoreCase))
        ?? throw new ArgumentOutOfRangeException(nameof(provider), provider, "Unknown AI provider.");

    private static int ParseInt(IReadOnlyDictionary<string, string> values, string key, int fallback) =>
        values.TryGetValue(key, out var value) && int.TryParse(value, out var parsed) ? parsed : fallback;
    private static bool ParseBool(IReadOnlyDictionary<string, string> values, string key, bool fallback) =>
        values.TryGetValue(key, out var value) && bool.TryParse(value, out var parsed) ? parsed : fallback;

    private sealed class StoredPromptRegistry
    {
        public List<StoredPromptTemplate> Templates { get; set; } = [];
    }

    private sealed class StoredPromptTemplate
    {
        public string Key { get; set; } = string.Empty;
        public List<StoredPromptTemplateVersion> Versions { get; set; } = [];
    }

    private sealed class StoredPromptTemplateVersion
    {
        public int Version { get; set; }
        public string EnglishText { get; set; } = string.Empty;
        public string ArabicText { get; set; } = string.Empty;
        public bool IsEnabled { get; set; } = true;
        public DateTime CreatedAtUtc { get; set; }
        public string UpdatedBy { get; set; } = string.Empty;
    }
}
