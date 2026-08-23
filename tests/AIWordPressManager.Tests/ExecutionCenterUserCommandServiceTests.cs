using System.Security.Claims;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Tests;

[Collection(WorkflowTestCollection.Name)]
public sealed class ExecutionCenterUserCommandServiceTests : IDisposable
{
    private readonly string _directory;
    private readonly ExecutionCenterService _executionCenter;

    public ExecutionCenterUserCommandServiceTests()
    {
        _directory = Path.Combine(Path.GetTempPath(), "AIWordPressManager.Tests", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_directory);
        _executionCenter = new ExecutionCenterService(
            Path.Combine(_directory, "execution-center-commands.db"));
    }

    [Fact]
    public void View_only_principal_cannot_cancel_owned_job()
    {
        var ownerUserId = Guid.NewGuid();
        var job = SeedJob(ownerUserId);
        var commands = CreateCommands(ownerUserId, ApplicationPermissionCatalog.OperationsView);

        var action = () => commands.Cancel(job.Id);

        action.Should().Throw<UnauthorizedAccessException>()
            .WithMessage($"*{ApplicationPermissionCatalog.OperationsExecute}*");
        _executionCenter.GetJobs(ownerUserId).Single(x => x.Id == job.Id).Status.Should().Be("Waiting");
    }

    [Fact]
    public void Execute_principal_can_cancel_owned_job()
    {
        var ownerUserId = Guid.NewGuid();
        var job = SeedJob(ownerUserId);
        var commands = CreateCommands(ownerUserId, ApplicationPermissionCatalog.OperationsExecute);

        commands.Cancel(job.Id);

        _executionCenter.GetJobs(ownerUserId).Single(x => x.Id == job.Id).Status.Should().Be("Cancelled");
    }

    [Fact]
    public void Execute_principal_cannot_mutate_another_owners_job()
    {
        var ownerUserId = Guid.NewGuid();
        var foreignOwnerUserId = Guid.NewGuid();
        var foreignJob = SeedJob(foreignOwnerUserId);
        var commands = CreateCommands(ownerUserId, ApplicationPermissionCatalog.OperationsExecute);

        var action = () => commands.Cancel(foreignJob.Id);

        action.Should().Throw<UnauthorizedAccessException>()
            .WithMessage("*does not belong*");
        _executionCenter.GetJobs(foreignOwnerUserId).Single(x => x.Id == foreignJob.Id).Status.Should().Be("Waiting");
    }

    [Fact]
    public void CanExecute_reflects_current_permission_claim()
    {
        var ownerUserId = Guid.NewGuid();

        CreateCommands(ownerUserId, ApplicationPermissionCatalog.OperationsView).CanExecute.Should().BeFalse();
        CreateCommands(ownerUserId, ApplicationPermissionCatalog.OperationsExecute).CanExecute.Should().BeTrue();
    }

    private ExecutionJob SeedJob(Guid ownerUserId) =>
        _executionCenter.Enqueue(
            ownerUserId,
            Guid.NewGuid(),
            "Permission boundary job",
            "Bulk operation",
            "Owned Site",
            totalItems: 10);

    private ExecutionCenterUserCommandService CreateCommands(Guid ownerUserId, params string[] permissions)
    {
        var claims = new List<Claim>
        {
            new(ClaimTypes.NameIdentifier, ownerUserId.ToString()),
            new(ClaimTypes.Name, "operations-user")
        };
        claims.AddRange(permissions.Select(permission =>
            new Claim(ApplicationPermissionCatalog.ClaimType, permission)));
        var accessor = new FixedHttpContextAccessor
        {
            HttpContext = new DefaultHttpContext
            {
                User = new ClaimsPrincipal(new ClaimsIdentity(claims, "test"))
            }
        };

        return new ExecutionCenterUserCommandService(
            _executionCenter,
            new CurrentUserContext(accessor));
    }

    public void Dispose()
    {
        _executionCenter.Dispose();
        SqliteConnection.ClearAllPools();
        try { Directory.Delete(_directory, true); } catch { }
    }

    private sealed class FixedHttpContextAccessor : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; }
    }
}
