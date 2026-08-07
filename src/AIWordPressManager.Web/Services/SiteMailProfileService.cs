using System.Net.Mail;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class SiteMailProfileService(
    AppDbContext dbContext,
    CurrentUserContext currentUser,
    ISecretProtectionService secretProtectionService,
    AccountEmailSettingsService accountEmailSettingsService)
{
    private Guid OwnerId => currentUser.UserId;

    public async Task<SiteMailProfileView> GetAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        await RequireOwnedSiteAsync(siteId, cancellationToken);
        var profile = await dbContext.SiteMailProfiles.AsNoTracking()
            .FirstOrDefaultAsync(x => x.SiteId == siteId && x.OwnerUserId == OwnerId, cancellationToken);

        return profile is null ? SiteMailProfileView.Default(siteId) : ToView(profile);
    }

    public async Task<SiteMailProfileView> SaveAsync(Guid siteId, SiteMailProfileInput input, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(input);
        await RequireOwnedSiteAsync(siteId, cancellationToken);

        var profile = await dbContext.SiteMailProfiles.FirstOrDefaultAsync(x => x.SiteId == siteId && x.OwnerUserId == OwnerId, cancellationToken);
        var now = DateTime.UtcNow;
        if (profile is null)
        {
            profile = new SiteMailProfile(siteId, OwnerId, now);
            dbContext.SiteMailProfiles.Add(profile);
        }

        if (input.UseAccountProfile)
        {
            profile.ConfigureInheritance(true, input.IsEnabled, now);
        }
        else
        {
            ValidateEmail(input.FromAddress, "From address");
            if (!string.IsNullOrWhiteSpace(input.ReplyToAddress)) ValidateEmail(input.ReplyToAddress, "Reply-to address");
            profile.ConfigureSmtp(input.Host, input.Port, input.UserName, input.FromAddress, input.FromName, input.ReplyToAddress, input.EnableSsl, input.IsEnabled, now);
        }

        if (!string.IsNullOrWhiteSpace(input.Password))
        {
            if (input.Password.Length > 2048) throw new InvalidOperationException("SMTP password is too long.");
            profile.SetProtectedPassword(await secretProtectionService.ProtectAsync(input.Password, cancellationToken), now);
        }

        await dbContext.SaveChangesAsync(cancellationToken);
        return ToView(profile);
    }

    public async Task ClearPasswordAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        await RequireOwnedSiteAsync(siteId, cancellationToken);
        var profile = await dbContext.SiteMailProfiles.FirstOrDefaultAsync(x => x.SiteId == siteId && x.OwnerUserId == OwnerId, cancellationToken)
            ?? throw new InvalidOperationException("Mail profile has not been configured for this site.");
        profile.ClearProtectedPassword(DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task<SiteMailDeliveryProfile?> GetDeliveryProfileAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        await RequireOwnedSiteAsync(siteId, cancellationToken);
        var profile = await dbContext.SiteMailProfiles.AsNoTracking()
            .FirstOrDefaultAsync(x => x.SiteId == siteId && x.OwnerUserId == OwnerId, cancellationToken);

        if (profile is null || !profile.IsEnabled) return null;
        if (profile.UseAccountProfile) return await accountEmailSettingsService.GetDeliveryProfileAsync(cancellationToken);

        string? password = null;
        if (!string.IsNullOrWhiteSpace(profile.ProtectedPassword))
            password = await secretProtectionService.UnprotectAsync(profile.ProtectedPassword, cancellationToken);

        return new SiteMailDeliveryProfile(profile.Host, profile.Port, profile.UserName, password, profile.FromAddress, profile.FromName, profile.ReplyToAddress, profile.EnableSsl);
    }

    private async Task RequireOwnedSiteAsync(Guid siteId, CancellationToken cancellationToken)
    {
        var ownsSite = await dbContext.Sites.AsNoTracking().AnyAsync(x => x.Id == siteId && x.OwnerUserId == OwnerId, cancellationToken);
        if (!ownsSite) throw new UnauthorizedAccessException("The requested WordPress site does not belong to the signed-in user.");
    }

    private static void ValidateEmail(string? value, string label)
    {
        if (string.IsNullOrWhiteSpace(value)) throw new InvalidOperationException($"{label} is required.");
        try
        {
            var parsed = new MailAddress(value.Trim());
            if (!string.Equals(parsed.Address, value.Trim(), StringComparison.OrdinalIgnoreCase)) throw new FormatException();
        }
        catch (FormatException) { throw new InvalidOperationException($"{label} is not a valid email address."); }
    }

    private static SiteMailProfileView ToView(SiteMailProfile profile) => new(profile.SiteId, profile.UseAccountProfile, profile.Host, profile.Port, profile.UserName, !string.IsNullOrWhiteSpace(profile.ProtectedPassword), profile.FromAddress, profile.FromName, profile.ReplyToAddress, profile.EnableSsl, profile.IsEnabled, profile.UpdatedAtUtc);
}

public sealed record SiteMailProfileInput(bool UseAccountProfile, string Host, int Port, string UserName, string Password, string FromAddress, string FromName, string ReplyToAddress, bool EnableSsl, bool IsEnabled);
public sealed record SiteMailProfileView(Guid SiteId, bool UseAccountProfile, string Host, int Port, string UserName, bool HasSavedPassword, string FromAddress, string FromName, string ReplyToAddress, bool EnableSsl, bool IsEnabled, DateTime? UpdatedAtUtc)
{
    public static SiteMailProfileView Default(Guid siteId) => new(siteId, true, string.Empty, 587, string.Empty, false, string.Empty, string.Empty, string.Empty, true, false, null);
}
public sealed record SiteMailDeliveryProfile(string Host, int Port, string UserName, string? Password, string FromAddress, string FromName, string ReplyToAddress, bool EnableSsl);
