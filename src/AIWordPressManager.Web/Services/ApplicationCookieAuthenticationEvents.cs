using System.Security.Claims;
using AIWordPressManager.Persistence;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

/// <summary>
/// Revalidates every authenticated cookie against the server-side session registry and current
/// account/role state. Stale role grants, disabled accounts, revoked sessions, and legacy cookies
/// without a tracked session identifier fail closed.
/// </summary>
public sealed class ApplicationCookieAuthenticationEvents(
    AppDbContext dbContext,
    ApplicationSessionStore sessionStore,
    ApplicationRoleStore roleStore) : CookieAuthenticationEvents
{
    public override async Task ValidatePrincipal(CookieValidatePrincipalContext context)
    {
        var principal = context.Principal;
        if (principal?.Identity?.IsAuthenticated != true ||
            !Guid.TryParse(principal.FindFirstValue(ClaimTypes.NameIdentifier), out var userId) ||
            !Guid.TryParse(principal.FindFirstValue(ApplicationSessionStore.SessionIdClaimType), out var sessionId))
        {
            await RejectAsync(context, sessionId: null, "Missing tracked session identity.");
            return;
        }

        var validation = await sessionStore.ValidateAsync(sessionId, userId, context.HttpContext.RequestAborted);
        if (!validation.IsValid)
        {
            await RejectAsync(context, sessionId, validation.Reason);
            return;
        }

        var user = await dbContext.AuthUsers.AsNoTracking()
            .Where(x => x.Id == userId)
            .Select(x => new { x.IsActive, x.Role })
            .SingleOrDefaultAsync(context.HttpContext.RequestAborted);
        if (user is null || !user.IsActive)
        {
            await RejectAsync(context, sessionId, "Account is unavailable or inactive.");
            return;
        }

        var resolvedRole = await roleStore.ResolveAssignableRoleNameAsync(user.Role, context.HttpContext.RequestAborted);
        if (resolvedRole is null)
        {
            await RejectAsync(context, sessionId, "Application role is unavailable or inactive.");
            return;
        }

        var cookieRole = principal.FindFirstValue(ClaimTypes.Role) ?? string.Empty;
        var currentPermissions = (await roleStore.ResolvePermissionsAsync(resolvedRole, context.HttpContext.RequestAborted))
            .OrderBy(value => value, StringComparer.Ordinal)
            .ToArray();
        var cookiePermissions = principal.FindAll(ApplicationPermissionCatalog.ClaimType)
            .Select(claim => claim.Value)
            .Distinct(StringComparer.Ordinal)
            .OrderBy(value => value, StringComparer.Ordinal)
            .ToArray();

        if (!string.Equals(cookieRole, resolvedRole, StringComparison.Ordinal) ||
            !cookiePermissions.SequenceEqual(currentPermissions, StringComparer.Ordinal))
        {
            await RejectAsync(context, sessionId, "Account role or permissions changed.");
            return;
        }

        await sessionStore.TouchAsync(sessionId, context.HttpContext.RequestAborted);
    }

    private async Task RejectAsync(CookieValidatePrincipalContext context, Guid? sessionId, string reason)
    {
        if (sessionId.HasValue)
        {
            try
            {
                await sessionStore.RevokeAsync(sessionId.Value, reason, context.HttpContext.RequestAborted);
            }
            catch
            {
                // Rejection must remain fail-closed even when the registry cannot be updated.
            }
        }

        context.RejectPrincipal();
        await context.HttpContext.SignOutAsync(CookieAuthenticationDefaults.AuthenticationScheme);
    }
}