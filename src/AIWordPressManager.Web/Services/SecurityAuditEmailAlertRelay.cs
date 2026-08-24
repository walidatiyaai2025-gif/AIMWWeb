using System.Globalization;
using System.Text.RegularExpressions;
using AIWordPressManager.Application.Abstractions.Email;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Web.Services;

/// <summary>
/// Relays persisted, high-signal application security audit records into the durable account email outbox.
/// The relay is background-safe: it never depends on CurrentUserContext or request-scoped actor state.
/// </summary>
public sealed class SecurityAuditEmailAlertRelay(
    AppDbContext dbContext,
    IEmailTemplateRenderer templateRenderer,
    IEmailOutbox emailOutbox,
    ILogger<SecurityAuditEmailAlertRelay> logger)
{
    private const int MaxCandidatesPerPass = 500;
    private const int MaxDetailsLength = 1000;
    private const int MaxLabelLength = 160;

    private static readonly Regex SecretAssignmentRegex = new(
        @"(?i)\b(password|passwd|pwd|token|api[-_]?key|secret|authorization|cookie|credential|connection[-_]?string)\s*[:=]\s*([^\s,;&]+)",
        RegexOptions.CultureInvariant | RegexOptions.Compiled);

    private static readonly Regex BearerRegex = new(
        @"(?i)\bbearer\s+[A-Za-z0-9\-._~+/]+=*",
        RegexOptions.CultureInvariant | RegexOptions.Compiled);

    private static readonly Regex UriUserInfoRegex = new(
        @"(?i)(https?://)[^/@\s]+@",
        RegexOptions.CultureInvariant | RegexOptions.Compiled);

    private static readonly Regex WhitespaceRegex = new(
        @"\s+",
        RegexOptions.CultureInvariant | RegexOptions.Compiled);

    public async Task<SecurityAuditEmailRelayResult> RelayPendingAsync(
        DateTime sinceUtc,
        IReadOnlySet<Guid>? alreadyHandled = null,
        CancellationToken cancellationToken = default)
    {
        sinceUtc = sinceUtc.Kind == DateTimeKind.Utc ? sinceUtc : sinceUtc.ToUniversalTime();
        var candidates = await LoadCandidatesAsync(sinceUtc, alreadyHandled, cancellationToken);
        var handled = new List<Guid>(candidates.Count);
        var enqueued = 0;
        var skipped = 0;
        var failed = 0;

        foreach (var record in candidates)
        {
            try
            {
                var owner = await ResolveOwnerAsync(record, cancellationToken);
                if (owner is null)
                {
                    skipped++;
                    handled.Add(record.EventId);
                    continue;
                }

                var idempotencyKey = BuildIdempotencyKey(record.EventId);
                var existing = await dbContext.EmailOutboxMessages.AsNoTracking()
                    .AnyAsync(x => x.OwnerUserId == owner.UserId && x.IdempotencyKey == idempotencyKey, cancellationToken);
                if (existing)
                {
                    enqueued++;
                    handled.Add(record.EventId);
                    continue;
                }

                var recipients = await dbContext.AccountEmailRecipients.AsNoTracking()
                    .Where(x => x.OwnerUserId == owner.UserId && x.IsEnabled)
                    .OrderBy(x => x.CreatedAtUtc)
                    .Select(x => x.EmailAddress)
                    .ToListAsync(cancellationToken);
                if (recipients.Count == 0)
                {
                    skipped++;
                    handled.Add(record.EventId);
                    continue;
                }

                var eventName = SanitizeSecurityValue(
                    $"{record.Action} ({record.Outcome})",
                    "Security event",
                    MaxLabelLength);
                var source = SanitizeSecurityValue(
                    $"{record.Category} / {record.TargetType}",
                    "Security audit",
                    MaxLabelLength);
                var details = BuildSanitizedDetails(record);
                var correlationId = BuildCorrelationId(record.EventId);
                var rendered = templateRenderer.Render(new EmailTemplateRenderRequest(
                    EmailTemplateKeys.SecurityAlert,
                    "en",
                    new Dictionary<string, string?>(StringComparer.OrdinalIgnoreCase)
                    {
                        ["AccountName"] = SanitizeSecurityValue(owner.UserName, "Account", MaxLabelLength),
                        ["EventName"] = eventName,
                        ["OccurredAt"] = FormatUtc(record.OccurredAtUtc),
                        ["Source"] = source,
                        ["CorrelationId"] = correlationId,
                        ["Details"] = details
                    }));

                await emailOutbox.EnqueueAsync(new EmailOutboxEnqueueRequest(
                    OwnerUserId: owner.UserId,
                    SiteId: null,
                    ScheduleId: null,
                    TemplateKey: rendered.TemplateKey,
                    Subject: rendered.Subject,
                    HtmlBody: rendered.HtmlBody,
                    TextBody: rendered.TextBody,
                    Recipients: recipients,
                    IdempotencyKey: idempotencyKey,
                    CorrelationId: correlationId,
                    MaxAttempts: 5), cancellationToken);

                enqueued++;
                handled.Add(record.EventId);
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                throw;
            }
            catch (Exception ex)
            {
                failed++;
                logger.LogWarning(
                    ex,
                    "Could not relay security audit event {SecurityAuditEventId}. The relay will retry it on a later pass.",
                    record.EventId);
            }
        }

        return new SecurityAuditEmailRelayResult(candidates.Count, enqueued, skipped, failed, handled);
    }

    internal static bool IsHighSignal(SecurityAuditRecord record)
    {
        if (EqualsIgnoreCase(record.Category, "Authentication") &&
            EqualsIgnoreCase(record.Action, "SignIn"))
            return EqualsIgnoreCase(record.Outcome, "Blocked");

        if (EqualsIgnoreCase(record.Category, "Account"))
        {
            if (EqualsIgnoreCase(record.Action, "User.RoleChanged"))
                return EqualsIgnoreCase(record.Outcome, "Blocked");

            if (!EqualsIgnoreCase(record.Outcome, "Succeeded"))
                return false;

            if (EqualsIgnoreCase(record.Action, "Password.Changed") ||
                EqualsIgnoreCase(record.Action, "Password.Reset") ||
                EqualsIgnoreCase(record.Action, "User.Disabled"))
                return true;

            return EqualsIgnoreCase(record.Action, "User.Updated") &&
                   record.Metadata.TryGetValue("roleChanged", out var roleChanged) &&
                   bool.TryParse(roleChanged, out var changed) && changed;
        }

        if (!EqualsIgnoreCase(record.Outcome, "Succeeded"))
            return false;

        if (EqualsIgnoreCase(record.Category, "Authorization"))
            return EqualsAny(record.Action, "Role.Created", "Role.Updated", "Role.Enabled", "Role.Disabled");

        if (EqualsIgnoreCase(record.Category, "Session"))
            return EqualsAny(record.Action, "Session.Revoked", "Session.UserBulkRevoked", "Session.SelfRevoked");

        if (EqualsIgnoreCase(record.Category, "Configuration"))
            return EqualsAny(record.Action, "AIProviders.Updated", "AIProvider.CredentialCleared");

        return false;
    }

    internal static string SanitizeForEmail(string? value, string fallback = "Security event", int maxLength = MaxDetailsLength) =>
        SanitizeSecurityValue(value, fallback, maxLength);

    private async Task<IReadOnlyList<SecurityAuditRecord>> LoadCandidatesAsync(
        DateTime sinceUtc,
        IReadOnlySet<Guid>? alreadyHandled,
        CancellationToken cancellationToken)
    {
        var store = new ApplicationSecurityAuditStore(dbContext);
        var retained = await store.ListRetainedAsync(sinceUtc, cancellationToken);

        return retained
            .Where(IsHighSignal)
            .Where(record => alreadyHandled?.Contains(record.EventId) != true)
            .OrderBy(record => record.OccurredAtUtc)
            .ThenBy(record => record.EventId)
            .Take(MaxCandidatesPerPass)
            .ToArray();
    }

    private async Task<SecurityAlertOwner?> ResolveOwnerAsync(
        SecurityAuditRecord record,
        CancellationToken cancellationToken)
    {
        if (EqualsIgnoreCase(record.TargetType, "ApplicationUser") &&
            Guid.TryParse(record.TargetId, out var targetUserId) &&
            targetUserId != Guid.Empty)
        {
            var target = await FindOwnerAsync(targetUserId, cancellationToken);
            if (target is not null)
                return target;
        }

        if (record.ActorUserId is { } actorUserId && actorUserId != Guid.Empty)
            return await FindOwnerAsync(actorUserId, cancellationToken);

        return null;
    }

    private Task<SecurityAlertOwner?> FindOwnerAsync(Guid userId, CancellationToken cancellationToken) =>
        dbContext.AuthUsers.AsNoTracking()
            .Where(x => x.Id == userId)
            .Select(x => new SecurityAlertOwner(x.Id, x.UserName))
            .SingleOrDefaultAsync(cancellationToken);

    private static string BuildSanitizedDetails(SecurityAuditRecord record)
    {
        var target = string.IsNullOrWhiteSpace(record.TargetDisplayName)
            ? record.TargetId
            : record.TargetDisplayName;
        target = SanitizeSecurityValue(target, record.TargetType, MaxLabelLength);

        var metadata = string.Join(
            "; ",
            record.Metadata
                .OrderBy(pair => pair.Key, StringComparer.OrdinalIgnoreCase)
                .Take(24)
                .Select(pair => $"{pair.Key}: {pair.Value}"));

        var raw = metadata.Length == 0
            ? $"Target: {target}"
            : $"Target: {target}; {metadata}";
        return SanitizeSecurityValue(raw, "Security audit event.", MaxDetailsLength);
    }

    private static string SanitizeSecurityValue(string? value, string fallback, int maxLength)
    {
        maxLength = Math.Clamp(maxLength, 1, MaxDetailsLength);
        var clean = string.IsNullOrWhiteSpace(value) ? fallback : value.Trim();
        clean = UriUserInfoRegex.Replace(clean, "$1[redacted]@");
        clean = BearerRegex.Replace(clean, "Bearer [redacted]");
        clean = SecretAssignmentRegex.Replace(clean, "$1=[redacted]");
        clean = WhitespaceRegex.Replace(clean, " ").Trim();
        if (clean.Length == 0) clean = fallback;
        return clean.Length <= maxLength ? clean : clean[..maxLength];
    }

    private static string FormatUtc(DateTime value) =>
        value.ToUniversalTime().ToString("yyyy-MM-dd HH:mm:ss 'UTC'", CultureInfo.InvariantCulture);

    private static string BuildIdempotencyKey(Guid eventId) => $"alert:security-audit:{eventId:N}";
    private static string BuildCorrelationId(Guid eventId) => $"security:{eventId:N}";

    private static bool EqualsIgnoreCase(string? left, string right) =>
        string.Equals(left, right, StringComparison.OrdinalIgnoreCase);

    private static bool EqualsAny(string? value, params string[] candidates) =>
        candidates.Any(candidate => EqualsIgnoreCase(value, candidate));

    private sealed record SecurityAlertOwner(Guid UserId, string UserName);
}

public sealed record SecurityAuditEmailRelayResult(
    int Scanned,
    int Enqueued,
    int Skipped,
    int Failed,
    IReadOnlyList<Guid> HandledEventIds);
