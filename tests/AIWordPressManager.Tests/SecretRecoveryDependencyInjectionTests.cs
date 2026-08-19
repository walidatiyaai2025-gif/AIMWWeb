using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Infrastructure;
using AIWordPressManager.Infrastructure.Security;
using FluentAssertions;
using Microsoft.Extensions.DependencyInjection;

namespace AIWordPressManager.Tests;

public sealed class SecretRecoveryDependencyInjectionTests
{
    [Fact]
    public void AddInfrastructure_RegistersProtectionAndRecoveryAgainstOneConcreteSingleton()
    {
        var services = new ServiceCollection();

        services.AddInfrastructure();

        var concrete = services.Single(x => x.ServiceType == typeof(DpapiSecretProtectionService));
        var protection = services.Single(x => x.ServiceType == typeof(ISecretProtectionService));
        var recovery = services.Single(x => x.ServiceType == typeof(ISecretRecoveryKeyService));

        concrete.Lifetime.Should().Be(ServiceLifetime.Singleton);
        protection.Lifetime.Should().Be(ServiceLifetime.Singleton);
        recovery.Lifetime.Should().Be(ServiceLifetime.Singleton);
        concrete.ImplementationType.Should().Be(typeof(DpapiSecretProtectionService));
        protection.ImplementationFactory.Should().NotBeNull();
        recovery.ImplementationFactory.Should().NotBeNull();
        typeof(ISecretProtectionService).IsAssignableFrom(typeof(DpapiSecretProtectionService)).Should().BeTrue();
        typeof(ISecretRecoveryKeyService).IsAssignableFrom(typeof(DpapiSecretProtectionService)).Should().BeTrue();
    }
}
