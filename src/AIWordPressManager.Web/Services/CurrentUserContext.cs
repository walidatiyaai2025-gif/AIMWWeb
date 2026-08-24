using System.Security.Claims;
using AIWordPressManager.Persistence;
using Microsoft.AspNetCore.Components.Authorization;

namespace AIWordPressManager.Web.Services;

public sealed class CurrentUserContext
{
    private readonly IHttpContextAccessor _accessor;
    private readonly AuthenticationStateProvider _authenticationStateProvider;
    private readonly ApplicationSessionRequestValidator? _sessionRequestValidator;
    private ClaimsPrincipal? _circuitPrincipal;

    // Production DI uses this constructor. Long-lived Interactive Server principals are
    // revalidated against durable session/account/role truth at security-sensitive boundaries.
    public CurrentUserContext(
        IHttpContextAccessor accessor,
        AuthenticationStateProvider authenticationStateProvider,
        AppDbContext dbContext)
        : this(accessor, authenticationStateProvider, new ApplicationSessionRequestValidator(dbContext))
    {
    }

    // Compatibility path for focused tests/adapters that provide an authentication-state
    // provider but do not own an application DbContext. This path intentionally has no live
    // session validator and should not be used by production DI.
    public CurrentUserContext(
        IHttpContextAccessor accessor,
        AuthenticationStateProvider authenticationStateProvider)
        : this(accessor, authenticationStateProvider, (ApplicationSessionRequestValidator?)null)
    {
    }

    // Compatibility path for the small number of infrastructure adapters that create
    // CurrentUserContext manually. DI uses the three-argument constructor above and gains
    // live Blazor circuit authorization revalidation; this overload intentionally remains HTTP-only.
    public CurrentUserContext(IHttpContextAccessor accessor)
        : this(accessor, new HttpContextAuthenticationStateProvider(accessor), (ApplicationSessionRequestValidator?)null)
    {
    }

    private CurrentUserContext(
        IHttpContextAccessor accessor,
        AuthenticationStateProvider authenticationStateProvider,
        ApplicationSessionRequestValidator? sessionRequestValidator)
    {
        _accessor = accessor;
        _authenticationStateProvider = authenticationStateProvider;
        _sessionRequestValidator = sessionRequestValidator;
    }

    public bool HasHttpContext => _accessor.HttpContext is not null;

    // Display/auth-state helpers intentionally read the circuit principal without touching
    // durable persistence on every Razor render. Operations that authorize or owner-scope
    // production work use Require*/TryGetUserId below and are live-revalidated fail closed.
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
        if (TryGetAuthorizedPrincipalUserId(out var userId))
            return userId;

        if (BackgroundExecutionIdentity.TryGetOwnerUserId(out userId))
            return userId;

        throw new UnauthorizedAccessException("Authenticated user identity is unavailable or no longer valid in the current HTTP request or Blazor circuit.");
    }

    public bool TryGetUserId(out Guid userId)
    {
        if (TryGetAuthorizedPrincipalUserId(out userId))
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
        // Elevated permissions require a currently-valid tracked application principal.
        // A cached circuit principal is revalidated against durable session, account, role
        // and permission truth immediately before it can authorize production work.
        var principal = ResolveAuthorizedPrincipal();
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
        var principal = ResolveAuthorizedPrincipal();
        if (principal?.Identity?.IsAuthenticated != true ||
            principal.IsInRole("Administrator") != true ||
            !TryGetPrincipalUserId(principal, out var userId))
        {
            throw new UnauthorizedAccessException("Administrator access is required.");
        }

        return userId;
    }

    public string UserName => ResolvePrincipal()?.Identity?.Name ?? string.Empty;

    private bool TryGetAuthorizedPrincipalUserId(out Guid userId) =>
        TryGetPrincipalUserId(ResolveAuthorizedPrincipal(), out userId);

    private static bool TryGetPrincipalUserId(ClaimsPrincipal? principal, out Guid userId)
    {
        var value = principal?.FindFirstValue(ClaimTypes.NameIdentifier);
        return Guid.TryParse(value, out userId);
    }

    private ClaimsPrincipal? ResolveAuthorizedPrincipal()
    {
        var principal = ResolvePrincipal();
        if (principal?.Identity?.IsAuthenticated != true || _sessionRequestValidator is null)
            return principal;

        SessionRequestValidationResult validation;
        try
        {
            validation = _sessionRequestValidator.ValidateForAuthorization(principal);
        }
        catch (InvalidOperationException)
        {
            // Security state that cannot be read consistently is not a reason to continue using
            // a cached authorization decision. Fail closed and require a fresh authenticated flow.
            validation = SessionRequestValidationResult.Invalid(null, "Authorization state is unavailable.");
        }

        if (validation.IsValid)
            return principal;

        if (_circuitPrincipal?.Identity?.IsAuthenticated == true)
            _circuitPrincipal = null;
        return null;
    }

    private ClaimsPrincipal? ResolvePrincipal()
    {
        var httpPrincipal = _accessor.HttpContext?.User;
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
            var state = _authenticationStateProvider.GetAuthenticationStateAsync().GetAwaiter().GetResult();
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

    private sealed class HttpContextAuthenticationStateProvider(IHttpContextAccessor httpAccessor) : AuthenticationStateProvider
    {
        public override Task<AuthenticationState> GetAuthenticationStateAsync()
        {
            var principal = httpAccessor.HttpContext?.User ?? new ClaimsPrincipal(new ClaimsIdentity());
            return Task.FromResult(new AuthenticationState(principal));
        }
    }
}
