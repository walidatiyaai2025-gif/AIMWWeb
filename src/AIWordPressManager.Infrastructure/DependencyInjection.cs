using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Infrastructure.AI;
using AIWordPressManager.Infrastructure.Billing;
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
        services.AddScoped<IPaymentGatewayRegistry, PaymentGatewayRegistry>();
        services.AddHttpClient<PayPalConfigurationDiagnostics>(client => client.Timeout = TimeSpan.FromSeconds(15));
        services.AddScoped<IPayPalConfigurationDiagnostics>(sp => sp.GetRequiredService<PayPalConfigurationDiagnostics>());
        services.AddHttpClient<PayPalPaymentGateway>(client => client.Timeout = TimeSpan.FromSeconds(30));
        services.AddHttpClient<PayPalLifecyclePaymentGateway>(client => client.Timeout = TimeSpan.FromSeconds(30));
        services.AddScoped<IPaymentGateway>(sp => sp.GetRequiredService<PayPalLifecyclePaymentGateway>());

        services.AddSingleton<VersionedAIPromptRegistry>();
        services.AddSingleton<IAIPromptRegistry>(sp => sp.GetRequiredService<VersionedAIPromptRegistry>());
        services.AddSingleton<IAIPromptTemplateStore>(sp => sp.GetRequiredService<VersionedAIPromptRegistry>());
        services.AddSingleton<IAIUsageLog, PersistentAIUsageLog>();
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
