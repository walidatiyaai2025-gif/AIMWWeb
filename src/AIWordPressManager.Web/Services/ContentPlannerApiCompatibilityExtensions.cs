namespace AIWordPressManager.Web.Services;

/// <summary>
/// Compatibility entry points for the existing minimal-API planner mappings in Program.cs.
/// All authorization, tenant resolution, ownership checks, persistence and audit remain inside
/// <see cref="ContentPlannerService"/>. Caller-supplied AI user identity is intentionally ignored.
/// </summary>
internal static class ContentPlannerApiCompatibilityExtensions
{
    public static PlannerItem Create(
        this ContentPlannerService service,
        CreatePlannerItem request) =>
        service.CreateAsync(request).GetAwaiter().GetResult();

    public static PlannerItem Update(
        this ContentPlannerService service,
        Guid id,
        UpdatePlannerItem request) =>
        service.UpdateAsync(id, request).GetAwaiter().GetResult();

    public static Task<PlannerItem> GenerateBriefAsync(
        this ContentPlannerService service,
        Guid id,
        string culture,
        string? callerSuppliedUserId,
        CancellationToken cancellationToken)
    {
        _ = callerSuppliedUserId;
        return service.GenerateBriefAsync(id, culture, cancellationToken);
    }

    public static Task<PlannerItem> GenerateDraftAsync(
        this ContentPlannerService service,
        Guid id,
        string culture,
        string? callerSuppliedUserId,
        CancellationToken cancellationToken)
    {
        _ = callerSuppliedUserId;
        return service.GenerateDraftAsync(id, culture, cancellationToken);
    }

    public static PlannerItem QueueForExecution(
        this ContentPlannerService service,
        Guid id) =>
        service.QueueForExecutionAsync(id).GetAwaiter().GetResult();
}
