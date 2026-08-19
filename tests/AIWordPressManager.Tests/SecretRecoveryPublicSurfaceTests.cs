using AIWordPressManager.Infrastructure.Security;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class SecretRecoveryPublicSurfaceTests
{
    [Fact]
    public void RawKeyStorageHelpers_AreNotPublicInfrastructureApi()
    {
        typeof(SecretProtectionStorage).IsPublic.Should().BeFalse();
        typeof(RuntimeLockLease).IsPublic.Should().BeFalse();

        var publicRecoveryMethods = typeof(OfflineSecretRecoveryInstaller)
            .GetMethods(System.Reflection.BindingFlags.Instance | System.Reflection.BindingFlags.Public | System.Reflection.BindingFlags.DeclaredOnly);

        publicRecoveryMethods.Should().NotContain(x => x.ReturnType == typeof(byte[]));
        publicRecoveryMethods.SelectMany(x => x.GetParameters())
            .Should().NotContain(x => x.ParameterType == typeof(byte[]));
    }
}
