namespace AIWordPressManager.Web.Services;

/// <summary>
/// User-facing command boundary for Execution Center lifecycle mutations. Background workers
/// continue to use the lower-level ExecutionCenterService trusted execution methods directly.
/// </summary>
public sealed class ExecutionCenterUserCommandService(
    ExecutionCenterService executionCenter,
    CurrentUserContext currentUser)
{
    public bool CanExecute => currentUser.HasPermission(ApplicationPermissionCatalog.OperationsExecute);

    public void Pause(Guid jobId) =>
        Execute(jobId, (id, ownerUserId) => executionCenter.Pause(id, ownerUserId));

    public void Resume(Guid jobId) =>
        Execute(jobId, (id, ownerUserId) => executionCenter.Resume(id, ownerUserId));

    public void Retry(Guid jobId) =>
        Execute(jobId, (id, ownerUserId) => executionCenter.Retry(id, ownerUserId));

    public void Cancel(Guid jobId) =>
        Execute(jobId, (id, ownerUserId) => executionCenter.Cancel(id, ownerUserId));

    private void Execute(Guid jobId, Action<Guid, Guid> command)
    {
        if (jobId == Guid.Empty)
            throw new ArgumentException("A valid execution job is required.", nameof(jobId));

        var ownerUserId = currentUser.RequirePermission(ApplicationPermissionCatalog.OperationsExecute);
        if (executionCenter.GetJobs(ownerUserId).All(job => job.Id != jobId))
            throw new UnauthorizedAccessException("The requested execution job does not belong to the signed-in user.");

        command(jobId, ownerUserId);
    }
}
