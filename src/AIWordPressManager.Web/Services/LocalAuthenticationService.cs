using System.Security.Claims;
using System.Text.RegularExpressions;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class LocalAuthenticationService(AppDbContext dbContext)
{
    public const string FirstRunLandingPath = "/welcome";

    private readonly PasswordHasher<AuthUser> _hasher = new();

    public Task<bool> HasAccountsAsync(CancellationToken cancellationToken = default) =>
        dbContext.AuthUsers.AsNoTracking().AnyAsync(cancellationToken);

    public async Task SeedAsync(CancellationToken cancellationToken = default)
    {
        var user = await dbContext.AuthUsers
            .FirstOrDefaultAsync(x => x.Role == "Administrator", cancellationToken);
        var now = DateTime.UtcNow;

        if (user is null)
        {
            const string normalized = "ADMIN";
            user = await dbContext.AuthUsers.SingleOrDefaultAsync(x => x.NormalizedUserName == normalized, cancellationToken);

            if (user is null)
            {
                user = new AuthUser("Admin", "temporary", now, "Administrator");
                user.SetPasswordHash(_hasher.HashPassword(user, "Admin@123"), now);
                dbContext.AuthUsers.Add(user);
                await dbContext.SaveChangesAsync(cancellationToken);
            }
            else if (!string.Equals(user.Role, "Administrator", StringComparison.OrdinalIgnoreCase))
            {
                user.SetRole("Administrator", now);
                await dbContext.SaveChangesAsync(cancellationToken);
            }
        }

        var unownedSites = await dbContext.Sites.IgnoreQueryFilters().Where(x => x.OwnerUserId == null).ToListAsync(cancellationToken);
        foreach (var site in unownedSites) site.AssignOwner(user.Id, now);
        if (unownedSites.Count > 0) await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task<RegistrationResult> CreateInitialAdministratorAsync(
        string userName,
        string password,
        string confirmPassword,
        CancellationToken cancellationToken = default)
    {
        if (await dbContext.AuthUsers.AnyAsync(cancellationToken))
            return RegistrationResult.Failed("An application account already exists. Initial administrator setup is no longer available.");

        var validation = ValidateRegistration(userName, password, confirmPassword);
        if (validation is not null) return RegistrationResult.Failed(validation);

        var cleanUserName = userName.Trim();
        var now = DateTime.UtcNow;
        var user = new AuthUser(cleanUserName, "temporary", now, "Administrator");
        user.SetPasswordHash(_hasher.HashPassword(user, password), now);
        dbContext.AuthUsers.Add(user);

        try
        {
            await dbContext.SaveChangesAsync(cancellationToken);
            return RegistrationResult.Succeeded(user.Id);
        }
        catch (DbUpdateException)
        {
            return RegistrationResult.Failed("The initial administrator account could not be created.");
        }
    }

    public async Task<RegistrationResult> RegisterAsync(
        string userName,
        string password,
        string confirmPassword,
        CancellationToken cancellationToken = default)
    {
        var validation = ValidateRegistration(userName, password, confirmPassword);
        if (validation is not null) return RegistrationResult.Failed(validation);

        var cleanUserName = userName.Trim();
        var normalized = cleanUserName.ToUpperInvariant();
        if (await dbContext.AuthUsers.AnyAsync(x => x.NormalizedUserName == normalized, cancellationToken))
            return RegistrationResult.Failed("This username is already registered.");

        var now = DateTime.UtcNow;
        var user = new AuthUser(cleanUserName, "temporary", now, "User");
        user.SetPasswordHash(_hasher.HashPassword(user, password), now);
        dbContext.AuthUsers.Add(user);

        try
        {
            await dbContext.SaveChangesAsync(cancellationToken);
            return RegistrationResult.Succeeded(user.Id);
        }
        catch (DbUpdateException)
        {
            return RegistrationResult.Failed("This username is already registered.");
        }
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

        var hasOwnedSites = await dbContext.Sites
            .AsNoTracking()
            .AnyAsync(x => x.OwnerUserId == user.Id, cancellationToken);

        var redirectPath = hasOwnedSites
            ? ResolveRedirectPath(returnUrl, user.LastPage)
            : ResolveRedirectPath(returnUrl, FirstRunLandingPath);

        return LoginResult.Succeeded(redirectPath);
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

    private static string? ValidateRegistration(string userName, string password, string confirmPassword)
    {
        var cleanUserName = (userName ?? string.Empty).Trim();
        var safePassword = password ?? string.Empty;
        var safeConfirmation = confirmPassword ?? string.Empty;

        if (cleanUserName.Length is < 3 or > 64)
            return "Username must be between 3 and 64 characters.";

        if (!Regex.IsMatch(cleanUserName, "^[A-Za-z0-9._-]+$"))
            return "Username can contain letters, numbers, dots, underscores, and hyphens only.";

        if (safePassword.Length < 8)
            return "Password must contain at least 8 characters.";

        if (!safePassword.Any(char.IsUpper) || !safePassword.Any(char.IsLower) || !safePassword.Any(char.IsDigit))
            return "Password must contain uppercase, lowercase, and numeric characters.";

        if (!string.Equals(safePassword, safeConfirmation, StringComparison.Ordinal))
            return "Password confirmation does not match.";

        return null;
    }

    private void AddAudit(HttpContext context, string userName, bool succeeded, string reason, DateTime utcNow)
    {
        dbContext.LoginAudits.Add(new LoginAudit(userName, succeeded, reason, context.Connection.RemoteIpAddress?.ToString(), context.Request.Headers.UserAgent.ToString(), now: utcNow));
    }

    private static bool IsSafeLocalPath(string? path)
    {
        if (string.IsNullOrWhiteSpace(path) || path[0] != '/' || path.StartsWith("//", StringComparison.Ordinal))
            return false;

        // Root-relative application paths are valid on every supported OS. Do not use
        // Uri.TryCreate(..., Absolute) here because Unix runtimes can interpret a path
        // such as "/sites" as an absolute file URI and incorrectly reject it.
        return !path.Contains('\\') && !path.Contains('\r') && !path.Contains('\n');
    }

    private static bool IsAuthenticationPath(string path) => path.StartsWith("/login", StringComparison.OrdinalIgnoreCase) || path.StartsWith("/logout", StringComparison.OrdinalIgnoreCase) || path.StartsWith("/register", StringComparison.OrdinalIgnoreCase);
}

public sealed record LoginResult(bool IsSuccess, string Message, string RedirectPath)
{
    public static LoginResult Failed(string message) => new(false, message, "/login");
    public static LoginResult Succeeded(string path) => new(true, string.Empty, path);
}

public sealed record RegistrationResult(bool IsSuccess, string Message, Guid? UserId)
{
    public static RegistrationResult Failed(string message) => new(false, message, null);
    public static RegistrationResult Succeeded(Guid userId) => new(true, string.Empty, userId);
}

public sealed record LoginAuditDto(Guid Id, string UserName, bool Succeeded, string Reason, string IpAddress, string UserAgent, DateTime AttemptedAtUtc);
