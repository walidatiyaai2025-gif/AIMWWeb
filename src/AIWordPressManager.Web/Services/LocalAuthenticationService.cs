using System.Security.Claims;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class LocalAuthenticationService(AppDbContext dbContext)
{
    private readonly PasswordHasher<AuthUser> _hasher = new();

    public async Task SeedAsync(CancellationToken cancellationToken = default)
    {
        const string normalized = "ADMIN";
        var user = await dbContext.AuthUsers.SingleOrDefaultAsync(x => x.NormalizedUserName == normalized, cancellationToken);
        var now = DateTime.UtcNow;

        if (user is null)
        {
            user = new AuthUser("Admin", "temporary", now);
            user.SetPasswordHash(_hasher.HashPassword(user, "Admin@123"), now);
            dbContext.AuthUsers.Add(user);
            await dbContext.SaveChangesAsync(cancellationToken);
        }

        var unownedSites = await dbContext.Sites.IgnoreQueryFilters().Where(x => x.OwnerUserId == null).ToListAsync(cancellationToken);
        foreach (var site in unownedSites) site.AssignOwner(user.Id, now);
        if (unownedSites.Count > 0) await dbContext.SaveChangesAsync(cancellationToken);
    }

    public Task<LoginResult> SignInAsync(HttpContext context, string userName, string password, bool rememberMe, CancellationToken cancellationToken = default) =>
        SignInAsync(context, userName, password, rememberMe, null, cancellationToken);

    public async Task<LoginResult> SignInAsync(HttpContext context, string userName, string password, bool rememberMe, string? returnUrl, CancellationToken cancellationToken = default)
    {
        var submittedUserName = (userName ?? string.Empty).Trim();
        var normalized = submittedUserName.ToUpperInvariant();
        var safePassword = password ?? string.Empty;
        var user = await dbContext.AuthUsers.SingleOrDefaultAsync(x => x.NormalizedUserName == normalized, cancellationToken);
        var now = DateTime.UtcNow;

        if (user is null || !user.IsActive)
        {
            AddAudit(context, submittedUserName, false, user is null ? "Unknown user" : "Inactive account", now);
            await dbContext.SaveChangesAsync(cancellationToken);
            return LoginResult.Failed("Invalid username or password.");
        }

        if (user.LockedUntilUtc is { } lockedUntil && lockedUntil > now)
        {
            AddAudit(context, user.UserName, false, "Account locked", now);
            await dbContext.SaveChangesAsync(cancellationToken);
            return LoginResult.Failed($"Account is locked until {lockedUntil.ToLocalTime():g}.");
        }

        var verification = _hasher.VerifyHashedPassword(user, user.PasswordHash, safePassword);
        if (verification == PasswordVerificationResult.Failed)
        {
            user.RecordFailedLogin(now);
            AddAudit(context, user.UserName, false, "Invalid password", now);
            await dbContext.SaveChangesAsync(cancellationToken);
            return LoginResult.Failed("Invalid username or password.");
        }

        if (verification == PasswordVerificationResult.SuccessRehashNeeded)
            user.SetPasswordHash(_hasher.HashPassword(user, safePassword), now);

        user.RecordSuccessfulLogin(now);
        AddAudit(context, user.UserName, true, "Success", now);
        await dbContext.SaveChangesAsync(cancellationToken);

        var claims = new[]
        {
            new Claim(ClaimTypes.NameIdentifier, user.Id.ToString()),
            new Claim(ClaimTypes.Name, user.UserName),
            new Claim(ClaimTypes.Role, user.Role)
        };
        var principal = new ClaimsPrincipal(new ClaimsIdentity(claims, CookieAuthenticationDefaults.AuthenticationScheme));
        await context.SignInAsync(CookieAuthenticationDefaults.AuthenticationScheme, principal, new AuthenticationProperties
        {
            IsPersistent = rememberMe,
            AllowRefresh = true,
            ExpiresUtc = rememberMe ? DateTimeOffset.UtcNow.AddDays(14) : null
        });

        return LoginResult.Succeeded(ResolveRedirectPath(returnUrl, user.LastPage));
    }

    public async Task<IReadOnlyList<LoginAuditDto>> GetRecentAuditsAsync(int take = 100, CancellationToken cancellationToken = default)
    {
        take = Math.Clamp(take, 1, 500);
        return await dbContext.LoginAudits.AsNoTracking().OrderByDescending(x => x.AttemptedAtUtc).Take(take)
            .Select(x => new LoginAuditDto(x.Id, x.UserName, x.Succeeded, x.Reason, x.IpAddress, x.UserAgent, x.AttemptedAtUtc)).ToListAsync(cancellationToken);
    }

    public async Task SaveLastPageAsync(ClaimsPrincipal principal, string path, CancellationToken cancellationToken = default)
    {
        if (!Guid.TryParse(principal.FindFirstValue(ClaimTypes.NameIdentifier), out var id)) return;
        var user = await dbContext.AuthUsers.SingleOrDefaultAsync(x => x.Id == id, cancellationToken);
        if (user is null) return;
        user.SetLastPage(path, DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public static string ResolveRedirectPath(string? requestedPath, string? lastPage)
    {
        if (IsSafeLocalPath(requestedPath) && !IsAuthenticationPath(requestedPath!)) return requestedPath!;
        if (IsSafeLocalPath(lastPage) && !IsAuthenticationPath(lastPage!)) return lastPage!;
        return "/";
    }

    private void AddAudit(HttpContext context, string userName, bool succeeded, string reason, DateTime utcNow)
    {
        dbContext.LoginAudits.Add(new LoginAudit(userName, succeeded, reason, context.Connection.RemoteIpAddress?.ToString(), context.Request.Headers.UserAgent.ToString(), utcNow));
    }

    private static bool IsSafeLocalPath(string? path) => !string.IsNullOrWhiteSpace(path) && path.StartsWith('/') && !path.StartsWith("//") && !Uri.TryCreate(path, UriKind.Absolute, out _);
    private static bool IsAuthenticationPath(string path) => path.StartsWith("/login", StringComparison.OrdinalIgnoreCase) || path.StartsWith("/logout", StringComparison.OrdinalIgnoreCase);
}

public sealed record LoginResult(bool IsSuccess, string Message, string RedirectPath)
{
    public static LoginResult Failed(string message) => new(false, message, "/login");
    public static LoginResult Succeeded(string path) => new(true, string.Empty, path);
}

public sealed record LoginAuditDto(Guid Id, string UserName, bool Succeeded, string Reason, string IpAddress, string UserAgent, DateTime AttemptedAtUtc);
