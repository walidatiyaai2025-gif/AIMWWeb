using System.Net;
using System.Net.Mail;
using System.Net.Sockets;
using System.Security.Authentication;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.Email;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class EmailOutboxWorker(
    IServiceScopeFactory scopeFactory,
    IConfiguration configuration,
    ILogger<EmailOutboxWorker> logger) : BackgroundService
{
    private static readonly TimeSpan IdleDelay = TimeSpan.FromSeconds(5);
    private static readonly TimeSpan SetupDelay = TimeSpan.FromSeconds(10);
    private static readonly TimeSpan StaleClaimAge = TimeSpan.FromMinutes(5);

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        logger.LogInformation("Email outbox worker started.");

        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                if (!configuration.GetValue<bool>("Database:SetupComplete"))
                {
                    await Task.Delay(SetupDelay, stoppingToken);
                    continue;
                }

                var processed = await ProcessNextAsync(stoppingToken);
                if (!processed) await Task.Delay(IdleDelay, stoppingToken);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                logger.LogError(ex, "Email outbox worker loop failed. The worker will retry after a short delay.");
                try { await Task.Delay(IdleDelay, stoppingToken); }
                catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested) { break; }
            }
        }

        logger.LogInformation("Email outbox worker stopped.");
    }

    private async Task<bool> ProcessNextAsync(CancellationToken stoppingToken)
    {
        using var scope = scopeFactory.CreateScope();
        var outbox = scope.ServiceProvider.GetRequiredService<IEmailOutbox>();
        var db = scope.ServiceProvider.GetRequiredService<AppDbContext>();
        var protection = scope.ServiceProvider.GetRequiredService<ISecretProtectionService>();
        var now = DateTime.UtcNow;

        var recovered = await outbox.RecoverStaleClaimsAsync(now.Subtract(StaleClaimAge), now, stoppingToken);
        if (recovered > 0)
            logger.LogWarning("Recovered {Count} stale email outbox claim(s) after an interrupted worker attempt.", recovered);

        var claim = await outbox.ClaimDueAsync(now, stoppingToken);
        if (claim is null) return false;

        try
        {
            var profile = await ResolveDeliveryProfileAsync(db, protection, claim, stoppingToken);
            await SendAsync(profile, claim, stoppingToken);
            await outbox.MarkSentAsync(
                claim.Id,
                claim.ClaimToken,
                "SMTP server accepted the message submission.",
                DateTime.UtcNow,
                stoppingToken);

            logger.LogInformation(
                "Email outbox message {MessageId} sent successfully. CorrelationId={CorrelationId} Attempt={Attempt}.",
                claim.Id,
                claim.CorrelationId,
                claim.AttemptNumber);
        }
        catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
        {
            logger.LogInformation(
                "Email outbox delivery for message {MessageId} was interrupted by application shutdown; stale-claim recovery will handle it after restart.",
                claim.Id);
            throw;
        }
        catch (Exception ex)
        {
            var failure = ClassifyFailure(ex);
            try
            {
                await outbox.MarkFailedAsync(
                    claim.Id,
                    claim.ClaimToken,
                    failure.Category,
                    failure.Message,
                    DateTime.UtcNow,
                    stoppingToken);
            }
            catch (Exception persistenceError)
            {
                logger.LogError(
                    persistenceError,
                    "Failed to persist email outbox failure for message {MessageId}. CorrelationId={CorrelationId}.",
                    claim.Id,
                    claim.CorrelationId);
                throw;
            }

            logger.LogWarning(
                "Email outbox message {MessageId} delivery failed. CorrelationId={CorrelationId} Category={Category} Attempt={Attempt}/{MaxAttempts}.",
                claim.Id,
                claim.CorrelationId,
                failure.Category,
                claim.AttemptNumber,
                claim.MaxAttempts);
        }

        return true;
    }

    private static async Task<SiteMailDeliveryProfile> ResolveDeliveryProfileAsync(
        AppDbContext db,
        ISecretProtectionService protection,
        EmailOutboxClaim claim,
        CancellationToken cancellationToken)
    {
        if (string.Equals(claim.Scope, EmailOutboxMessage.SiteScope, StringComparison.OrdinalIgnoreCase))
        {
            if (!claim.SiteId.HasValue)
                throw new InvalidOperationException("The site for this queued email no longer exists.");

            var ownedSiteExists = await db.Sites.AsNoTracking()
                .AnyAsync(x => x.Id == claim.SiteId.Value && x.OwnerUserId == claim.OwnerUserId, cancellationToken);
            if (!ownedSiteExists)
                throw new InvalidOperationException("The site for this queued email is no longer available to its owner.");

            var siteProfile = await db.SiteMailProfiles.AsNoTracking()
                .FirstOrDefaultAsync(x => x.SiteId == claim.SiteId.Value && x.OwnerUserId == claim.OwnerUserId, cancellationToken)
                ?? throw new InvalidOperationException("The site mail profile is not configured.");

            if (!siteProfile.IsEnabled)
                throw new InvalidOperationException("Outbound email is disabled for this site.");

            if (!siteProfile.UseAccountProfile)
                return await BuildSiteProfileAsync(siteProfile, protection, cancellationToken);
        }

        var accountProfile = await db.AccountMailProfiles.AsNoTracking()
            .FirstOrDefaultAsync(x => x.OwnerUserId == claim.OwnerUserId, cancellationToken)
            ?? throw new InvalidOperationException("The account mail profile is not configured.");

        if (!accountProfile.IsEnabled)
            throw new InvalidOperationException("Outbound email is disabled for this account.");

        return await BuildAccountProfileAsync(accountProfile, protection, cancellationToken);
    }

    private static async Task<SiteMailDeliveryProfile> BuildSiteProfileAsync(
        SiteMailProfile profile,
        ISecretProtectionService protection,
        CancellationToken cancellationToken)
    {
        string? password = null;
        if (!string.IsNullOrWhiteSpace(profile.ProtectedPassword))
            password = await protection.UnprotectAsync(profile.ProtectedPassword, cancellationToken);
        return new SiteMailDeliveryProfile(
            profile.Host,
            profile.Port,
            profile.UserName,
            password,
            profile.FromAddress,
            profile.FromName,
            profile.ReplyToAddress,
            profile.EnableSsl);
    }

    private static async Task<SiteMailDeliveryProfile> BuildAccountProfileAsync(
        AccountMailProfile profile,
        ISecretProtectionService protection,
        CancellationToken cancellationToken)
    {
        string? password = null;
        if (!string.IsNullOrWhiteSpace(profile.ProtectedPassword))
            password = await protection.UnprotectAsync(profile.ProtectedPassword, cancellationToken);
        return new SiteMailDeliveryProfile(
            profile.Host,
            profile.Port,
            profile.UserName,
            password,
            profile.FromAddress,
            profile.FromName,
            profile.ReplyToAddress,
            profile.EnableSsl);
    }

    private static async Task SendAsync(
        SiteMailDeliveryProfile profile,
        EmailOutboxClaim claim,
        CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(profile.Host)) throw new InvalidOperationException("SMTP host is not configured.");
        if (profile.Port is < 1 or > 65535) throw new InvalidOperationException("SMTP port is invalid.");
        if (claim.Recipients.Count == 0) throw new InvalidOperationException("Queued email has no recipients.");

        using var message = new MailMessage
        {
            From = string.IsNullOrWhiteSpace(profile.FromName)
                ? new MailAddress(profile.FromAddress)
                : new MailAddress(profile.FromAddress, profile.FromName),
            Subject = claim.Subject,
            Body = claim.HtmlBody,
            IsBodyHtml = true
        };

        foreach (var recipient in claim.Recipients)
            message.To.Add(new MailAddress(recipient));
        if (!string.IsNullOrWhiteSpace(profile.ReplyToAddress))
            message.ReplyToList.Add(new MailAddress(profile.ReplyToAddress));
        if (!string.IsNullOrWhiteSpace(claim.TextBody))
            message.AlternateViews.Add(AlternateView.CreateAlternateViewFromString(claim.TextBody, null, "text/plain"));

#pragma warning disable SYSLIB0014
        using var smtp = new SmtpClient(profile.Host, profile.Port)
        {
            EnableSsl = profile.EnableSsl,
            DeliveryMethod = SmtpDeliveryMethod.Network,
            UseDefaultCredentials = false,
            Timeout = 30000
        };
#pragma warning restore SYSLIB0014

        if (!string.IsNullOrWhiteSpace(profile.UserName))
            smtp.Credentials = new NetworkCredential(profile.UserName, profile.Password ?? string.Empty);

        await smtp.SendMailAsync(message).WaitAsync(TimeSpan.FromSeconds(35), cancellationToken);
    }

    private static DeliveryFailure ClassifyFailure(Exception exception) => exception switch
    {
        AuthenticationException => new("TLS", "TLS authentication failed. Check certificate trust and SMTP TLS requirements."),
        SocketException => new("Network", "The SMTP server could not be reached. Check DNS, firewall, host, and port."),
        TimeoutException => new("Timeout", "SMTP delivery timed out before the server accepted the message."),
        SmtpException smtp => new("SMTP", smtp.StatusCode switch
        {
            SmtpStatusCode.ClientNotPermitted or SmtpStatusCode.MustIssueStartTlsFirst =>
                "SMTP server rejected the connection policy. Check TLS requirements and sender permissions.",
            SmtpStatusCode.MailboxUnavailable or SmtpStatusCode.MailboxBusy =>
                "SMTP server could not accept one or more recipients.",
            _ => "SMTP server rejected the message. Check authentication, TLS, sender permissions, and provider policy."
        }),
        InvalidOperationException invalid => new("Configuration", SanitizeConfigurationMessage(invalid.Message)),
        FormatException => new("Configuration", "The saved SMTP sender or recipient address is invalid."),
        _ => new("Delivery", "Email delivery failed because of an unexpected provider or network error.")
    };

    private static string SanitizeConfigurationMessage(string message)
    {
        var allowed = new[]
        {
            "The site for this queued email no longer exists.",
            "The site for this queued email is no longer available to its owner.",
            "The site mail profile is not configured.",
            "Outbound email is disabled for this site.",
            "The account mail profile is not configured.",
            "Outbound email is disabled for this account.",
            "SMTP host is not configured.",
            "SMTP port is invalid.",
            "Queued email has no recipients."
        };
        return allowed.Contains(message, StringComparer.Ordinal) ? message : "The saved email configuration is incomplete or invalid.";
    }

    private sealed record DeliveryFailure(string Category, string Message);
}
