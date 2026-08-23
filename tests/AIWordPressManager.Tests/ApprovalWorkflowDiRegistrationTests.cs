using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.Extensions.DependencyInjection;

namespace AIWordPressManager.Tests;

public sealed class ApprovalWorkflowDiRegistrationTests : IDisposable
{
    private readonly string _testDirectory = Path.Combine(
        Path.GetTempPath(),
        "AIWordPressManager.Tests",
        Guid.NewGuid().ToString("N"));

    [Fact]
    public void Runtime_registration_resolves_without_constructor_ambiguity()
    {
        Directory.CreateDirectory(_testDirectory);

        using var executionCenter = new ExecutionCenterService(
            Path.Combine(_testDirectory, "execution-center.db"));
        var notifications = NotificationInboxService.ForDatabase(
            Path.Combine(_testDirectory, "notifications.db"));

        var services = new ServiceCollection();
        services.AddHttpContextAccessor();
        services.AddSingleton(executionCenter);
        services.AddSingleton(notifications);
        services.AddSingleton<ApprovalWorkflowService>(sp =>
            new ApprovalWorkflowService(
                sp.GetRequiredService<ExecutionCenterService>(),
                sp.GetRequiredService<NotificationInboxService>(),
                sp.GetRequiredService<IServiceScopeFactory>(),
                sp.GetRequiredService<IHttpContextAccessor>()));

        using var provider = services.BuildServiceProvider(new ServiceProviderOptions
        {
            ValidateOnBuild = true,
            ValidateScopes = true
        });

        var resolve = () => provider.GetRequiredService<ApprovalWorkflowService>();
        resolve.Should().NotThrow();
    }

    public void Dispose()
    {
        SqliteConnection.ClearAllPools();
        if (!Directory.Exists(_testDirectory)) return;

        try
        {
            Directory.Delete(_testDirectory, recursive: true);
        }
        catch (IOException)
        {
            // Best effort cleanup only; pooled handles can be released slightly later on Windows CI agents.
        }
        catch (UnauthorizedAccessException)
        {
            // Best effort cleanup only.
        }
    }
}
