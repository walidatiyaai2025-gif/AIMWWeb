using AIWordPressManager.Infrastructure.Jobs;

namespace AIWordPressManager.Web.Services;

public static class BackgroundJobsApi
{
    public static IEndpointRouteBuilder MapBackgroundJobsApi(this IEndpointRouteBuilder endpoints)
    {
        var group = endpoints.MapGroup("/api/background-jobs")
            .WithTags("Background Jobs");

        group.MapGet("/", (int? take, BackgroundJobManagementService service) =>
            Results.Ok(service.GetRecent(take ?? 100)));

        group.MapGet("/{id:guid}", (Guid id, BackgroundJobManagementService service) =>
            service.Get(id) is { } job ? Results.Ok(job) : Results.NotFound());

        group.MapPost("/test-delay", async (
            CreateDelayJobRequest request,
            HttpContext httpContext,
            BackgroundJobManagementService service,
            CancellationToken cancellationToken) =>
        {
            var seconds = Math.Clamp(request.Seconds, 1, 120);
            var requestedBy = httpContext.User.Identity?.Name ?? request.RequestedBy;
            var id = await service.EnqueueSystemDelayAsync(
                seconds,
                string.IsNullOrWhiteSpace(request.Message) ? "Infrastructure test job" : request.Message.Trim(),
                request.SiteId,
                requestedBy,
                cancellationToken);

            return Results.Accepted($"/api/background-jobs/{id}", new { id });
        });

        group.MapPost("/{id:guid}/cancel", (Guid id, BackgroundJobManagementService service) =>
            service.Cancel(id)
                ? Results.Ok(service.Get(id))
                : Results.NotFound(new { error = "Job was not found or can no longer be cancelled." }));

        return endpoints;
    }
}

public sealed record CreateDelayJobRequest(
    int Seconds = 10,
    string? Message = null,
    Guid? SiteId = null,
    string? RequestedBy = null);
