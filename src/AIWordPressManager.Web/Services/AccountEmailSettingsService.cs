using System.Net.Mail;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class AccountEmailSettingsService(
    AppDbContext dbContext,
    CurrentUserContext currentUser,
    ISecretProtectionService secretProtectionService)
{
    private Guid OwnerId => currentUser.UserId;

    public async Task<AccountEmailSettingsView> GetAsync(CancellationToken cancellationToken = default)
    {
        var recipients = await dbContext.AccountEmailRecipients.AsNoTracking()
            .Where(x => x.OwnerUserId == OwnerId)
            .OrderBy(x => x.CreatedAtUtc)
            .Select(x => new AccountEmailRecipientView(x.Id, x.EmailAddress, x.DisplayName, x.IsEnabled))
            .ToListAsync(cancellationToken);

        var profile = await dbContext.AccountMailProfiles.AsNoTracking()
            .FirstOrDefaultAsync(x => x.OwnerUserId == OwnerId, cancellationToken);

        return new AccountEmailSettingsView(recipients, profile is null ? AccountMailProfileView.Default() : ToView(profile));
    }

    public async Task<Guid> AddRecipientAsync(string emailAddress, string? displayName, CancellationToken cancellationToken = default)
    {
        ValidateEmail(emailAddress, "Email address");
        var normalized = emailAddress.Trim().ToUpperInvariant();
        var count = await dbContext.AccountEmailRecipients.CountAsync(x => x.OwnerUserId == OwnerId, cancellationToken);
        if (count >= 3) throw new InvalidOperationException("An account can have a maximum of three dashboard email recipients.");
        if (await dbContext.AccountEmailRecipients.AnyAsync(x => x.OwnerUserId == OwnerId && x.NormalizedEmailAddress == normalized, cancellationToken))
            throw new InvalidOperationException("This email address is already configured for the account.");

        var item = new AccountEmailRecipient(OwnerId, emailAddress, displayName, DateTime.UtcNow);
        dbContext.AccountEmailRecipients.Add(item);
        await dbContext.SaveChangesAsync(cancellationToken);
        return item.Id;
    }

    public async Task UpdateRecipientAsync(Guid id, string emailAddress, string? displayName, bool enabled, CancellationToken cancellationToken = default)
    {
        ValidateEmail(emailAddress, "Email address");
        var normalized = emailAddress.Trim().ToUpperInvariant();
        var item = await dbContext.AccountEmailRecipients.FirstOrDefaultAsync(x => x.Id == id && x.OwnerUserId == OwnerId, cancellationToken)
            ?? throw new UnauthorizedAccessException("The requested dashboard email recipient does not belong to the signed-in user.");
        if (await dbContext.AccountEmailRecipients.AnyAsync(x => x.OwnerUserId == OwnerId && x.Id != id && x.NormalizedEmailAddress == normalized, cancellationToken))
            throw new InvalidOperationException("This email address is already configured for the account.");
        item.Update(emailAddress, displayName, enabled, DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task DeleteRecipientAsync(Guid id, CancellationToken cancellationToken = default)
    {
        var item = await dbContext.AccountEmailRecipients.FirstOrDefaultAsync(x => x.Id == id && x.OwnerUserId == OwnerId, cancellationToken)
            ?? throw new UnauthorizedAccessException("The requested dashboard email recipient does not belong to the signed-in user.");
        dbContext.AccountEmailRecipients.Remove(item);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task<AccountMailProfileView> SaveProfileAsync(AccountMailProfileInput input, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(input);
        ValidateEmail(input.FromAddress, "From address");
        if (!string.IsNullOrWhiteSpace(input.ReplyToAddress)) ValidateEmail(input.ReplyToAddress, "Reply-to address");

        var profile = await dbContext.AccountMailProfiles.FirstOrDefaultAsync(x => x.OwnerUserId == OwnerId, cancellationToken);
        var now = DateTime.UtcNow;
        if (profile is null)
        {
            profile = new AccountMailProfile(OwnerId, now);
            dbContext.AccountMailProfiles.Add(profile);
        }

        profile.Configure(input.Host, input.Port, input.UserName, input.FromAddress, input.FromName, input.ReplyToAddress, input.EnableSsl, input.IsEnabled, now);
        if (!string.IsNullOrWhiteSpace(input.Password))
        {
            if (input.Password.Length > 2048) throw new InvalidOperationException("SMTP password is too long.");
            profile.SetProtectedPassword(await secretProtectionService.ProtectAsync(input.Password, cancellationToken), now);
        }

        await dbContext.SaveChangesAsync(cancellationToken);
        return ToView(profile);
    }

    public async Task ClearPasswordAsync(CancellationToken cancellationToken = default)
    {
        var profile = await dbContext.AccountMailProfiles.FirstOrDefaultAsync(x => x.OwnerUserId == OwnerId, cancellationToken)
            ?? throw new InvalidOperationException("Dashboard mail profile has not been configured.");
        profile.ClearProtectedPassword(DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task<SiteMailDeliveryProfile?> GetDeliveryProfileAsync(CancellationToken cancellationToken = default)
    {
        var profile = await dbContext.AccountMailProfiles.AsNoTracking().FirstOrDefaultAsync(x => x.OwnerUserId == OwnerId, cancellationToken);
        if (profile is null || !profile.IsEnabled) return null;
        return await BuildDeliveryProfileAsync(profile, cancellationToken);
    }

    public async Task<SiteMailDeliveryProfile> GetTestProfileAsync(CancellationToken cancellationToken = default)
    {
        var profile = await dbContext.AccountMailProfiles.AsNoTracking().FirstOrDefaultAsync(x => x.OwnerUserId == OwnerId, cancellationToken)
            ?? throw new InvalidOperationException("Save the account SMTP profile before running diagnostics.");
        return await BuildDeliveryProfileAsync(profile, cancellationToken);
    }

    private async Task<SiteMailDeliveryProfile> BuildDeliveryProfileAsync(AccountMailProfile profile, CancellationToken cancellationToken)
    {
        string? password = null;
        if (!string.IsNullOrWhiteSpace(profile.ProtectedPassword))
            password = await secretProtectionService.UnprotectAsync(profile.ProtectedPassword, cancellationToken);
        return new SiteMailDeliveryProfile(profile.Host, profile.Port, profile.UserName, password, profile.FromAddress, profile.FromName, profile.ReplyToAddress, profile.EnableSsl);
    }

    private static AccountMailProfileView ToView(AccountMailProfile profile) => new(profile.Host, profile.Port, profile.UserName, !string.IsNullOrWhiteSpace(profile.ProtectedPassword), profile.FromAddress, profile.FromName, profile.ReplyToAddress, profile.EnableSsl, profile.IsEnabled, profile.UpdatedAtUtc);

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
}

public sealed record AccountEmailRecipientView(Guid Id, string EmailAddress, string? DisplayName, bool IsEnabled);
public sealed record AccountEmailSettingsView(IReadOnlyList<AccountEmailRecipientView> Recipients, AccountMailProfileView Profile);
public sealed record AccountMailProfileInput(string Host, int Port, string UserName, string Password, string FromAddress, string FromName, string ReplyToAddress, bool EnableSsl, bool IsEnabled);
public sealed record AccountMailProfileView(string Host, int Port, string UserName, bool HasSavedPassword, string FromAddress, string FromName, string ReplyToAddress, bool EnableSsl, bool IsEnabled, DateTime? UpdatedAtUtc)
{
    public static AccountMailProfileView Default() => new(string.Empty, 587, string.Empty, false, string.Empty, string.Empty, string.Empty, true, false, null);
}
