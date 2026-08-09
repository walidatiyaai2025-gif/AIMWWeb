using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Infrastructure.AI;
using AIWordPressManager.Infrastructure.Paths;
using AIWordPressManager.Infrastructure.Jobs;
using AIWordPressManager.Infrastructure.Security;
using AIWordPressManager.Infrastructure.Time;
using Microsoft.Extensions.DependencyInjection;

namespace AIWordPressManager.Infrastructure;

public static class DependencyInjection
{
    public static IServiceCollection AddInfrastructure(this IServiceCollection services)
    {
        services.AddSingleton<IApplicationPathService, ApplicationPathService>();
        services.AddSingleton<ISecretProtectionService, DpapiSecretProtectionService>();
        services.AddSingleton<IClock, SystemClock>();
        services.AddSingleton<IJobCancellationRegistry, JobCancellationRegistry>();

        services.AddSingleton<VersionedAIPromptRegistry>();
        services.AddSingleton<IAIPromptRegistry>(sp => sp.GetRequiredService<VersionedAIPromptRegistry>());
        services.AddSingleton<IAIPromptTemplateStore>(sp => sp.GetRequiredService<VersionedAIPromptRegistry>());
        services.AddSingleton<IAIUsageLog, AIUsageLog>();
        services.AddSingleton<IAIContentProtector, AIContentProtector>();
        services.AddScoped<AIProviderRuntimeSettingsResolver>();
        services.AddScoped<IAIOrchestrator, SettingsAwareAIOrchestrator>();
        services.AddHttpClient<SettingsBackedOpenAIProvider>();
        services.AddHttpClient<SettingsBackedGeminiProvider>();
        services.AddHttpClient<SettingsBackedPuterProvider>();
        services.AddScoped<IAIProvider>(sp => sp.GetRequiredService<SettingsBackedOpenAIProvider>());
        services.AddScoped<IAIProvider>(sp => sp.GetRequiredService<SettingsBackedGeminiProvider>());
        services.AddScoped<IAIProvider>(sp => sp.GetRequiredService<SettingsBackedPuterProvider>());

        return services;
    }
}
