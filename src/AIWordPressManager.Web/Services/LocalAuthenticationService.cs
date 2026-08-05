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
        if (await dbContext.AuthUsers.AnyAsync(x => x.NormalizedUserName == normalized, cancellationToken))
            return;

        var now = DateTime.UtcNow;
        var user = new AuthUser("Admin", "temporary", now);
        user.SetPasswordHash(_hasher.HashPassword(user, "Admin@123"), now);
        dbContext.AuthUsers.Add(user);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public Task<LoginResult> SignInAsync(
        HttpContext context,
        string userName,
        string password,
        bool rememberMe,
        CancellationToken cancellationToken = default) =>
        SignInAsync(context, userName, password, rememberMe, null, cancellationToken);

    public async Task<LoginResult> SignInAsync(
        HttpContext context,
        string userName,
        string password,
        bool rememberMe,
        string? returnUrl,
        CancellationToken cancellationToken = default)
    {
        var normalized = (userName ?? string.Empty).Trim().ToUpperInvariant();
        var safePassword = password ?? string.Empty;
        var user = await dbContext.AuthUsers.SingleOrDefaultAsync(x => x.NormalizedUserName == normalized, cancellationToken);
        var now = DateTime.UtcNow;

        if (user is null || !user.IsActive)
            return LoginResult.Failed("Invalid username or password.");

        if (user.LockedUntilUtc is { } lockedUntil && lockedUntil > now)
            return LoginResult.Failed($"Account is locked until {lockedUntil.ToLocalTime():g}.");

        var verification = _hasher.VerifyHashedPassword(user, user.PasswordHash, safePassword);
        if (verification == PasswordVerificationResult.Failed)
        {
            user.RecordFailedLogin(now);
            await dbContext.SaveChangesAsync(cancellationToken);
            return LoginResult.Failed("Invalid username or password.");
        }

        if (verification == PasswordVerificationResult.SuccessRehashNeeded)
            user.SetPasswordHash(_hasher.HashPassword(user, safePassword), now);

        user.RecordSuccessfulLogin(now);
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
        if (IsSafeLocalPath(requestedPath) && !IsAuthenticationPath(requestedPath!))
            return requestedPath!;

        if (IsSafeLocalPath(lastPage) && !IsAuthenticationPath(lastPage!))
            return lastPage!;

        return "/";
    }

    private static bool IsSafeLocalPath(string? path)
    {
        if (string.IsNullOrWhiteSpace(path) || !path.StartsWith('/') || path.StartsWith("//"))
            return false;

        return !Uri.TryCreate(path, UriKind.Absolute, out _);
    }

    private static bool IsAuthenticationPath(string path) =>
        path.StartsWith("/login", StringComparison.OrdinalIgnoreCase) ||
        path.StartsWith("/logout", StringComparison.OrdinalIgnoreCase);
}

public sealed record LoginResult(bool IsSuccess, string Message, string RedirectPath)
{
    public static LoginResult Failed(string message) => new(false, message, "/login");
    public static LoginResult Succeeded(string path) => new(true, string.Empty, path);
}
