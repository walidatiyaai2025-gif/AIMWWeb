using System.Security.Claims;

namespace AIWordPressManager.Web.Services;

public sealed class CurrentUserContext(IHttpContextAccessor accessor)
{
    public Guid UserId
    {
        get
        {
            var value = accessor.HttpContext?.User.FindFirstValue(ClaimTypes.NameIdentifier);
            return Guid.TryParse(value, out var id) ? id : throw new UnauthorizedAccessException("Authenticated user identity is unavailable.");
        }
    }

    public string UserName => accessor.HttpContext?.User.Identity?.Name ?? string.Empty;
    public bool IsAuthenticated => accessor.HttpContext?.User.Identity?.IsAuthenticated == true;
}
