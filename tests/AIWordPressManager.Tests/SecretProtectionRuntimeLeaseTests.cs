using AIWordPressManager.Infrastructure.Security;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class SecretProtectionRuntimeLeaseTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), $"aiwm-runtime-lease-{Guid.NewGuid():N}");

    [Fact]
    public void MultipleWebLeasesCanCoexistButRecoveryRequiresAllWorkersStopped()
    {
        var localApplicationData = Path.Combine(_root, "local");
        using var firstWeb = SecretProtectionStorage.AcquireWebRuntimeLease(localApplicationData);
        using var secondWeb = SecretProtectionStorage.AcquireWebRuntimeLease(localApplicationData);

        var blocked = () => SecretProtectionStorage.AcquireRecoveryExclusiveLease(localApplicationData);
        blocked.Should().Throw<InvalidOperationException>()
            .WithMessage("*Stop every web application worker*");

        secondWeb.Dispose();
        var stillBlocked = () => SecretProtectionStorage.AcquireRecoveryExclusiveLease(localApplicationData);
        stillBlocked.Should().Throw<InvalidOperationException>();

        firstWeb.Dispose();
        using var recovery = SecretProtectionStorage.AcquireRecoveryExclusiveLease(localApplicationData);
        recovery.Path.Should().EndWith(SecretProtectionStorage.RuntimeLockFileName);
    }

    [Fact]
    public void RecoveryExclusiveLeaseBlocksNewWebWorkerUntilRecoveryCompletes()
    {
        var localApplicationData = Path.Combine(_root, "exclusive-local");
        using var recovery = SecretProtectionStorage.AcquireRecoveryExclusiveLease(localApplicationData);

        var blockedWeb = () => SecretProtectionStorage.AcquireWebRuntimeLease(localApplicationData);
        blockedWeb.Should().Throw<InvalidOperationException>()
            .WithMessage("*recovery*finish*web application*");

        recovery.Dispose();
        using var web = SecretProtectionStorage.AcquireWebRuntimeLease(localApplicationData);
        web.Path.Should().EndWith(SecretProtectionStorage.RuntimeLockFileName);
    }

    public void Dispose()
    {
        try
        {
            if (Directory.Exists(_root)) Directory.Delete(_root, recursive: true);
        }
        catch
        {
            // Best-effort cleanup only.
        }
    }
}
