using System.Net.Mail;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class SiteEmailRecipientService(
    AppDbContext dbContext,
    CurrentUserContext currentUser,
    IAccountEntitlementEnforcementService entitlementEnforcement)
{
    private Guid OwnerId => currentUser.UserId;

    public async Task<IReadOnlyList<SiteEmailRecipientView>> GetAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        await RequireOwnedSiteAsync(siteId, cancellationToken);
        return await dbContext.SiteEmailRecipients.AsNoTracking()
            .Where(x => x.SiteId == siteId && x.OwnerUserId == OwnerId)
            .OrderBy(x => x.CreatedAtUtc)
            .Select(x => new SiteEmailRecipientView(x.Id, x.EmailAddress, x.DisplayName, x.IsEnabled))
            .ToListAsync(cancellationToken);
    }

    public async Task<Guid> AddAsync(Guid siteId, string emailAddress, string? displayName, CancellationToken cancellationToken = default)
    {
        await RequireOwnedSiteAsync(siteId, cancellationToken);
        var normalized = ValidateAndNormalize(emailAddress);

        var duplicate = await dbContext.SiteEmailRecipients.AnyAsync(
            x => x.SiteId == siteId && x.OwnerUserId == OwnerId && x.NormalizedEmailAddress == normalized,
            cancellationToken);
        if (duplicate) throw new InvalidOperationException("This email address is already configured for the selected site.");

        var currentUsage = await dbContext.SiteEmailRecipients.AsNoTracking().LongCountAsync(
            x => x.SiteId == siteId && x.OwnerUserId == OwnerId,
            cancellationToken);
        await entitlementEnforcement.RequireAdditionalUsageAsync(
            OwnerId,
            EntitlementDefinitionCatalog.EmailSiteRecipientsMax,
            currentUsage,
            1,
            cancellationToken);

        var recipient = new SiteEmailRecipient(siteId, OwnerId, emailAddress.Trim(), displayName, DateTime.UtcNow);
        dbContext.SiteEmailRecipients.Add(recipient);
        await dbContext.SaveChangesAsync(cancellationToken);
        return recipient.Id;
    }

    public async Task UpdateAsync(Guid siteId, Guid recipientId, string emailAddress, string? displayName, bool enabled, CancellationToken cancellationToken = default)
    {
        await RequireOwnedSiteAsync(siteId, cancellationToken);
        var normalized = ValidateAndNormalize(emailAddress);
        var recipient = await dbContext.SiteEmailRecipients.FirstOrDefaultAsync(
            x => x.Id == recipientId && x.SiteId == siteId && x.OwnerUserId == OwnerId,
            cancellationToken) ?? throw new InvalidOperationException("Email recipient was not found.");

        var duplicate = await dbContext.SiteEmailRecipients.AnyAsync(
            x => x.Id != recipientId && x.SiteId == siteId && x.OwnerUserId == OwnerId && x.NormalizedEmailAddress == normalized,
            cancellationToken);
        if (duplicate) throw new InvalidOperationException("This email address is already configured for the selected site.");

        recipient.Update(emailAddress.Trim(), displayName, enabled, DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task DeleteAsync(Guid siteId, Guid recipientId, CancellationToken cancellationToken = default)
    {
        await RequireOwnedSiteAsync(siteId, cancellationToken);
        var recipient = await dbContext.SiteEmailRecipients.FirstOrDefaultAsync(
            x => x.Id == recipientId && x.SiteId == siteId && x.OwnerUserId == OwnerId,
            cancellationToken);
        if (recipient is null) return;
        dbContext.SiteEmailRecipients.Remove(recipient);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    private async Task RequireOwnedSiteAsync(Guid siteId, CancellationToken cancellationToken)
    {
        var exists = await dbContext.Sites.AsNoTracking().AnyAsync(x => x.Id == siteId && x.OwnerUserId == OwnerId, cancellationToken);
        if (!exists) throw new UnauthorizedAccessException("The requested WordPress site does not belong to the signed-in user.");
    }

    private static string ValidateAndNormalize(string emailAddress)
    {
        if (string.IsNullOrWhiteSpace(emailAddress)) throw new InvalidOperationException("Email address is required.");
        var clean = emailAddress.Trim();
        if (clean.Length > 320) throw new InvalidOperationException("Email address is too long.");
        try
        {
            var parsed = new MailAddress(clean);
            if (!string.Equals(parsed.Address, clean, StringComparison.OrdinalIgnoreCase))
                throw new FormatException();
        }
        catch (FormatException)
        {
            throw new InvalidOperationException("Enter a valid email address.");
        }
        return clean.ToUpperInvariant();
    }
}

public sealed record SiteEmailRecipientView(Guid Id, string EmailAddress, string? DisplayName, bool IsEnabled);
