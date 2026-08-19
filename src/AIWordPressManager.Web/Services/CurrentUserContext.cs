using System.Security.Claims;
using Microsoft.AspNetCore.Components.Authorization;

namespace AIWordPressManager.Web.Services;

public sealed class CurrentUserContext(
    IHttpContextAccessor accessor,
    AuthenticationStateProvider authenticationStateProvider)
{
    private ClaimsPrincipal? _circuitPrincipal;

    public bool HasHttpContext => accessor.HttpContext is not null;
    public bool IsAuthenticated => ResolvePrincipal()?.Identity?.IsAuthenticated == true || BackgroundExecutionIdentity.TryGetOwnerUserId(out _);

    public Guid UserId => RequireUserId();

    /// <summary>
    /// Caches the authenticated Blazor circuit principal. Interactive Server components
    /// cannot rely on IHttpContextAccessor after the initial HTTP request has completed.
    /// </summary>
    public void SetCircuitPrincipal(ClaimsPrincipal? principal)
    {
        if (principal?.Identity?.IsAuthenticated == true)
            _circuitPrincipal = principal;
    }

    public Guid RequireUserId()
    {
        if (TryGetPrincipalUserId(out var userId))
            return userId;

        if (BackgroundExecutionIdentity.TryGetOwnerUserId(out userId))
            return userId;

        throw new UnauthorizedAccessException("Authenticated user identity is unavailable in the current HTTP request or Blazor circuit.");
    }

    public bool TryGetUserId(out Guid userId)
    {
        if (TryGetPrincipalUserId(out userId))
            return true;

        return BackgroundExecutionIdentity.TryGetOwnerUserId(out userId);
    }

    public bool TryGetSessionId(out Guid sessionId)
    {
        var value = ResolvePrincipal()?.FindFirstValue(ApplicationSessionStore.SessionIdClaimType);
        return Guid.TryParse(value, out sessionId);
    }

    public bool IsInRole(string role) => ResolvePrincipal()?.IsInRole(role) == true;

    public bool HasPermission(string permission) =>
        ApplicationPermissionCatalog.PrincipalHasPermission(ResolvePrincipal(), permission);

    public Guid RequirePermission(string permission)
    {
        // Elevated permissions still require an authenticated application principal.
        // The principal may come from the initial HTTP request or the authenticated
        // Blazor circuit. Background owner identity alone is never sufficient here.
        var principal = ResolvePrincipal();
        if (principal?.Identity?.IsAuthenticated != true ||
            !ApplicationPermissionCatalog.PrincipalHasPermission(principal, permission) ||
            !TryGetPrincipalUserId(principal, out var userId))
        {
            throw new UnauthorizedAccessException($"Permission '{permission}' is required.");
        }

        return userId;
    }

    public Guid RequireAdministrator()
    {
        var principal = ResolvePrincipal();
        if (principal?.Identity?.IsAuthenticated != true ||
            principal.IsInRole("Administrator") != true ||
            !TryGetPrincipalUserId(principal, out var userId))
        {
            throw new UnauthorizedAccessException("Administrator access is required.");
        }

        return userId;
    }

    public string UserName => ResolvePrincipal()?.Identity?.Name ?? string.Empty;

    private bool TryGetPrincipalUserId(out Guid userId) =>
        TryGetPrincipalUserId(ResolvePrincipal(), out userId);

    private static bool TryGetPrincipalUserId(ClaimsPrincipal? principal, out Guid userId)
    {
        var value = principal?.FindFirstValue(ClaimTypes.NameIdentifier);
        return Guid.TryParse(value, out userId);
    }

    private ClaimsPrincipal? ResolvePrincipal()
    {
        var httpPrincipal = accessor.HttpContext?.User;
        if (httpPrincipal?.Identity?.IsAuthenticated == true)
        {
            _circuitPrincipal = httpPrincipal;
            return httpPrincipal;
        }

        if (_circuitPrincipal?.Identity?.IsAuthenticated == true)
            return _circuitPrincipal;

        // ServerAuthenticationStateProvider normally returns an already-completed task.
        // Resolve it here only as a fallback for scoped services called directly from an
        // Interactive Server component. Fail closed if no circuit auth state is available.
        try
        {
            var state = authenticationStateProvider.GetAuthenticationStateAsync().GetAwaiter().GetResult();
            if (state.User.Identity?.IsAuthenticated == true)
            {
                _circuitPrincipal = state.User;
                return state.User;
            }
        }
        catch (InvalidOperationException)
        {
            // No Blazor authentication state is available in this scope (for example,
            // a non-circuit background scope). BackgroundExecutionIdentity is handled by
            // RequireUserId/TryGetUserId and never grants elevated permissions.
        }

        return null;
    }
}