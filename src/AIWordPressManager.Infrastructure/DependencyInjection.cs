using AIWordPressManager.Application.Abstractions;
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
        return services;
    }
}
