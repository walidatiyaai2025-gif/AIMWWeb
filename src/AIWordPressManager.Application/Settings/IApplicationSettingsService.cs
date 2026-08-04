namespace AIWordPressManager.Application.Settings;

public sealed record SynchronizationSettings(int IntervalMinutes, bool RunOnStartup, bool OfflineFirst);

public sealed record AiProviderSettings(
    string Provider,
    bool Enabled,
    int Priority,
    string Model,
    string ProtectedApiKey,
    bool HasApiKey);

public sealed record AiSettings(
    bool Enabled,
    bool AutomaticFallback,
    IReadOnlyList<AiProviderSettings> Providers);

public sealed record PerformanceSettings(bool EnableMemoryCooling, int CoolingThresholdPercent, int ResumeThresholdPercent, int CheckIntervalSeconds, bool KillChildProcessesOnExit);

public sealed record JobReliabilitySettings(
    bool PauseAfterFailures,
    int ConsecutiveFailuresBeforePause,
    int FailurePauseMinutes,
    bool AutoResumeAfterPause);


public sealed record AiAutomationSettings(
    bool EnableAiErrorDiagnosis,
    string ErrorDecisionMode,
    bool AutoExecuteLowRiskAiActions,
    bool AutoRejectHighRiskAiActions,
    bool CaptureBeforeAfterEvidence,
    bool RequireVerifiedExecutionResult,
    int MinimumSplashSeconds);

public sealed record DestructiveOperationSettings(
    bool EnableContentTrash,
    bool EnablePermanentContentDelete,
    bool EnablePermanentMediaDelete,
    bool RequireBackupBeforePermanentDelete);

public interface IApplicationSettingsService
{
    Task<SynchronizationSettings> GetSynchronizationSettingsAsync(CancellationToken cancellationToken = default);
    Task SaveSynchronizationSettingsAsync(SynchronizationSettings settings, CancellationToken cancellationToken = default);

    Task<AiSettings> GetAiSettingsAsync(CancellationToken cancellationToken = default);
    Task SaveAiSettingsAsync(
        AiSettings settings,
        IReadOnlyDictionary<string, string?> plainApiKeys,
        CancellationToken cancellationToken = default);

    Task<PerformanceSettings> GetPerformanceSettingsAsync(CancellationToken cancellationToken = default);
    Task SavePerformanceSettingsAsync(PerformanceSettings settings, CancellationToken cancellationToken = default);

    Task<JobReliabilitySettings> GetJobReliabilitySettingsAsync(CancellationToken cancellationToken = default);
    Task SaveJobReliabilitySettingsAsync(JobReliabilitySettings settings, CancellationToken cancellationToken = default);

    Task<AiAutomationSettings> GetAiAutomationSettingsAsync(CancellationToken cancellationToken = default);
    Task SaveAiAutomationSettingsAsync(AiAutomationSettings settings, CancellationToken cancellationToken = default);

    Task<DestructiveOperationSettings> GetDestructiveOperationSettingsAsync(CancellationToken cancellationToken = default);
    Task SaveDestructiveOperationSettingsAsync(DestructiveOperationSettings settings, CancellationToken cancellationToken = default);
}
