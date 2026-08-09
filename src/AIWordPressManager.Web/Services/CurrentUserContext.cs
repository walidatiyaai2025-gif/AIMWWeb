using System.Security.Claims;

namespace AIWordPressManager.Web.Services;

public sealed class CurrentUserContext(IHttpContextAccessor accessor)
{
    public bool HasHttpContext => accessor.HttpContext is not null;
    public bool IsAuthenticated => accessor.HttpContext?.User.Identity?.IsAuthenticated == true || BackgroundExecutionIdentity.TryGetOwnerUserId(out _);

    public Guid UserId => RequireUserId();

    public Guid RequireUserId()
    {
        if (TryGetHttpUserId(out var userId))
            return userId;

        if (BackgroundExecutionIdentity.TryGetOwnerUserId(out userId))
            return userId;

        throw new UnauthorizedAccessException("Authenticated user identity is unavailable.");
    }

    public bool TryGetUserId(out Guid userId)
    {
        if (TryGetHttpUserId(out userId))
            return true;

        return BackgroundExecutionIdentity.TryGetOwnerUserId(out userId);
    }

    public bool IsInRole(string role) => accessor.HttpContext?.User.IsInRole(role) == true;

    public Guid RequireAdministrator()
    {
        if (accessor.HttpContext?.User.Identity?.IsAuthenticated != true ||
            !IsInRole("Administrator") ||
            !TryGetHttpUserId(out var userId))
        {
            throw new UnauthorizedAccessException("Administrator access is required.");
        }

        return userId;
    }

    public string UserName => accessor.HttpContext?.User.Identity?.Name ?? string.Empty;

    private bool TryGetHttpUserId(out Guid userId)
    {
        var value = accessor.HttpContext?.User.FindFirstValue(ClaimTypes.NameIdentifier);
        return Guid.TryParse(value, out userId);
    }
}
