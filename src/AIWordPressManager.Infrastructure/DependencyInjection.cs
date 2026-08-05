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

        services.AddSingleton<IAIPromptRegistry, AIPromptRegistry>();
        services.AddSingleton<IAIUsageLog, AIUsageLog>();
        services.AddSingleton<IAIContentProtector, AIContentProtector>();
        services.AddScoped<IAIOrchestrator, AIOrchestrator>();
        services.AddHttpClient<OpenAIProvider>();
        services.AddHttpClient<GeminiProvider>();
        services.AddHttpClient<PuterProvider>();
        services.AddScoped<IAIProvider>(sp => sp.GetRequiredService<OpenAIProvider>());
        services.AddScoped<IAIProvider>(sp => sp.GetRequiredService<GeminiProvider>());
        services.AddScoped<IAIProvider>(sp => sp.GetRequiredService<PuterProvider>());

        return services;
    }
}
