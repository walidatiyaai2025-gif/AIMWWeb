using System.Collections.Concurrent;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Application.Settings;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Infrastructure.AI;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Billing;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class SeoRemediationWebService(
    AppDbContext dbContext,
    CurrentUserContext currentUser,
    IAccountEntitlementEnforcementService entitlementEnforcement,
    AIProviderRuntimeSettingsResolver providerSettings,
    IConfiguration configuration,
    IAIOrchestrator ai,
    IWordPressPostEditorService editor,
    WordPressSyncWebService synchronization,
    ApplicationSecurityAuditService audit,
    ApplicationSecurityAuditStore auditStore)
{
    private const int MaximumTargets = 50;
    private const int MaximumSourceLength = 12_000;
    private const int MaximumSuggestionLength = 300;
    private const string AuditCategory = "AI SEO Remediation";
    private readonly ConcurrentDictionary<Guid, SeoRemediationProposal> _proposals = [];

    public IReadOnlyList<SeoRemediationProposal> GetProposals(Guid siteId) =>
        _proposals.Values.Where(x => x.SiteId == siteId).OrderBy(x => x.GeneratedAtUtc).ToArray();

    public async Task<SeoRemediationGenerationResult> GenerateAsync(
        Guid siteId,
        IReadOnlyCollection<SeoRemediationTarget>? targets = null,
        CancellationToken cancellationToken = default)
    {
        var actor = await AuthorizeAsync(siteId, requireEdit: false, cancellationToken);
        var readiness = await GetProviderReadinessAsync(cancellationToken);
        if (readiness.State != SeoAiProviderState.Ready)
            return new(readiness, Array.Empty<SeoRemediationProposal>());

        var resolvedTargets = targets is { Count: > 0 }
            ? targets.Take(MaximumTargets).ToArray()
            : await ResolveTargetsAsync(siteId, cancellationToken);

        var generated = new List<SeoRemediationProposal>(resolvedTargets.Length);
        foreach (var target in resolvedTargets)
        {
            cancellationToken.ThrowIfCancellationRequested();
            var remote = await editor.GetAsync(siteId, target.ContentType, target.ContentId, cancellationToken);
            if (remote.IsFailure)
            {
                generated.Add(StoreFailure(siteId, target, remote.Error.Message));
                continue;
            }

            var current = GetField(remote.Value, target.Field);
            if (current.Length > 300)
            {
                generated.Add(StoreFailure(siteId, target, "The current field exceeds the safe reversible remediation limit."));
                continue;
            }
            var correlationId = Guid.NewGuid();
            var response = await ai.ExecuteAsync(new AIRequest(
                BuildPrompt(remote.Value, target.Field, current),
                "Return one JSON object only with suggestedValue, reason, confidence. Do not include markdown or unsupported fields.",
                Temperature: 0.1,
                MaxOutputTokens: 300,
                SiteId: siteId,
                UserId: actor.ToString("D"),
                Operation: "seo-remediation-proposal"), cancellationToken);

            ParsedSuggestion parsed = default!;
            var parseError = "The AI provider request failed.";
            var validResponse = response.IsSuccess && TryParse(response.Content, target.Field, current, out parsed, out parseError);
            if (!validResponse)
            {
                generated.Add(StoreFailure(siteId, target, response.IsSuccess ? parseError : Sanitize(response.Error)));
                continue;
            }

            var proposal = new SeoRemediationProposal(
                Guid.NewGuid(), correlationId, siteId, target.ContentId, NormalizeType(target.ContentType), target.Field,
                current, parsed.SuggestedValue, parsed.Reason, parsed.Confidence,
                SafetyFor(target.Field), SeoRemediationProposalState.AiSuggested, remote.Value.ModifiedGmt,
                DateTimeOffset.UtcNow, response.Provider, response.Model ?? string.Empty, string.Empty);
            _proposals[proposal.ProposalId] = proposal;
            generated.Add(proposal);
        }

        return new(readiness, generated);
    }

    public async Task<SeoAiProviderReadiness> GetProviderReadinessAsync(CancellationToken cancellationToken = default)
    {
        var settings = await providerSettings.GetSettingsAsync(cancellationToken);
        if (!settings.Enabled)
            return new(SeoAiProviderState.Unavailable, "Not configured", "AI features are disabled in application settings.");

        var enabled = settings.Providers
            .Where(x => x.Enabled && AIProviderRuntimeCatalog.IsAvailable(x.Provider))
            .OrderBy(x => x.Priority)
            .ToArray();
        if (enabled.Length == 0)
            return new(SeoAiProviderState.NotConfigured, "Not configured", "No AI provider is enabled.");

        foreach (var provider in enabled)
        {
            if (provider.Provider.Equals("Puter", StringComparison.OrdinalIgnoreCase))
            {
                var endpoint = configuration["AI:Puter:Endpoint"];
                if (Uri.TryCreate(endpoint, UriKind.Absolute, out var uri) && uri.Scheme == Uri.UriSchemeHttps)
                    return new(SeoAiProviderState.Ready, provider.Provider, string.Empty);
                if (!string.IsNullOrWhiteSpace(endpoint))
                    return new(SeoAiProviderState.Unavailable, provider.Provider, "The Puter endpoint is invalid.");
                continue;
            }

            var runtime = await providerSettings.ResolveAsync(
                provider.Provider,
                $"AI:{provider.Provider}:ApiKey",
                $"AI:{provider.Provider}:Model",
                provider.Model,
                cancellationToken);
            if (provider.HasApiKey || !string.IsNullOrWhiteSpace(runtime.ApiKey))
                return new(SeoAiProviderState.Ready, provider.Provider, string.Empty);
        }

        return new(SeoAiProviderState.NotConfigured, "Not configured", "AI Provider not configured.");
    }

    public Task<SeoRemediationApplyResult> ApplyAsync(Guid siteId, Guid proposalId, CancellationToken cancellationToken = default) =>
        ApplyCoreAsync(siteId, proposalId, false, cancellationToken);

    public Task<SeoRemediationBulkResult> ApplySelectedAsync(Guid siteId, IEnumerable<Guid> proposalIds, CancellationToken cancellationToken = default) =>
        ApplyManyAsync(siteId, proposalIds.Distinct().Take(MaximumTargets).ToArray(), cancellationToken);

    public Task<SeoRemediationBulkResult> ApplyAllSafeAsync(Guid siteId, CancellationToken cancellationToken = default) =>
        ApplyManyAsync(siteId, _proposals.Values.Where(x => x.SiteId == siteId && x.State == SeoRemediationProposalState.AiSuggested && x.SafetyClass == SeoRemediationSafetyClass.SafeAutomatic).Select(x => x.ProposalId).ToArray(), cancellationToken);

    public Task<SeoRemediationBulkResult> RetryFailedAsync(Guid siteId, CancellationToken cancellationToken = default) =>
        ApplyManyAsync(siteId, _proposals.Values.Where(x => x.SiteId == siteId && x.State == SeoRemediationProposalState.Failed && !string.IsNullOrWhiteSpace(x.SuggestedValue)).Select(x => x.ProposalId).ToArray(), cancellationToken);

    public async Task<IReadOnlyList<SeoRemediationAuditEntry>> GetHistoryAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        await AuthorizeAsync(siteId, requireEdit: false, cancellationToken);
        var records = await auditStore.ListRetainedAsync(DateTime.UtcNow.AddDays(-90), cancellationToken);
        return records.Where(x => x.Category == AuditCategory && x.Metadata.TryGetValue("siteId", out var id) && id == siteId.ToString("D"))
            .Select(ToHistory).Where(x => x is not null).Cast<SeoRemediationAuditEntry>()
            .OrderByDescending(x => x.OccurredAtUtc).ToArray();
    }

    public async Task<SeoRemediationApplyResult> UndoAsync(Guid siteId, Guid auditId, CancellationToken cancellationToken = default)
    {
        await AuthorizeAsync(siteId, requireEdit: true, cancellationToken);
        var entry = (await GetHistoryAsync(siteId, cancellationToken)).FirstOrDefault(x => x.AuditId == auditId);
        if (entry is null || entry.Result != SeoRemediationProposalState.Verified)
            return new(Guid.Empty, SeoRemediationProposalState.Failed, "Undo record is unavailable.", false, null);

        var remote = await editor.GetAsync(siteId, entry.ContentType, entry.ContentId, cancellationToken);
        if (remote.IsFailure || !string.Equals(GetField(remote.Value, entry.Field), entry.AfterValue, StringComparison.Ordinal))
            return new(entry.ProposalId, SeoRemediationProposalState.Conflict, "Undo was blocked because the current WordPress value changed.", false, null);

        var synthetic = new SeoRemediationProposal(Guid.NewGuid(), Guid.NewGuid(), siteId, entry.ContentId, entry.ContentType,
            entry.Field, entry.AfterValue, entry.BeforeValue, "Undo verified AI SEO remediation", 1m,
            SeoRemediationSafetyClass.SafeAutomatic, SeoRemediationProposalState.AiSuggested, remote.Value.ModifiedGmt,
            DateTimeOffset.UtcNow, entry.Provider, string.Empty, string.Empty);
        _proposals[synthetic.ProposalId] = synthetic;
        return await ApplyCoreAsync(siteId, synthetic.ProposalId, true, cancellationToken);
    }

    private async Task<SeoRemediationBulkResult> ApplyManyAsync(Guid siteId, IReadOnlyCollection<Guid> ids, CancellationToken cancellationToken)
    {
        // This scoped service owns a scoped DbContext and scoped authorization boundary. A single
        // worker is deliberately used so bulk work is bounded without concurrently using those
        // non-thread-safe dependencies. Each item still reaches an independent terminal result.
        var items = new List<SeoRemediationApplyResult>(ids.Count);
        foreach (var id in ids)
        {
            cancellationToken.ThrowIfCancellationRequested();
            items.Add(await ApplyCoreAsync(siteId, id, false, cancellationToken));
        }
        return new(items.Count, items.Count(x => x.State == SeoRemediationProposalState.Verified),
            items.Count(x => x.State == SeoRemediationProposalState.Failed), items.Count(x => x.State == SeoRemediationProposalState.Conflict),
            items.Count(x => x.State == SeoRemediationProposalState.NeedsReview), items);
    }

    private async Task<SeoRemediationApplyResult> ApplyCoreAsync(Guid siteId, Guid proposalId, bool isUndo, CancellationToken cancellationToken)
    {
        var actor = await AuthorizeAsync(siteId, requireEdit: true, cancellationToken);
        if (!_proposals.TryGetValue(proposalId, out var proposal) || proposal.SiteId != siteId)
            return new(proposalId, SeoRemediationProposalState.Failed, "Proposal was not found.", false, null);
        if (proposal.SafetyClass != SeoRemediationSafetyClass.SafeAutomatic)
            return await UpdateAsync(proposal, actor, SeoRemediationProposalState.NeedsReview, "This field requires review and cannot be applied automatically.", cancellationToken);

        var remote = await editor.GetAsync(siteId, proposal.ContentType, proposal.ContentId, cancellationToken);
        if (remote.IsFailure) return await UpdateAsync(proposal, actor, SeoRemediationProposalState.Failed, remote.Error.Message, cancellationToken);
        var current = GetField(remote.Value, proposal.Field);
        if (string.Equals(current, proposal.SuggestedValue, StringComparison.Ordinal))
            return await RecordVerifiedAsync(proposal, actor, current, true, isUndo, cancellationToken);
        if (!string.Equals(current, proposal.CurrentValue, StringComparison.Ordinal) ||
            WordPressPostEditorWebService.HasRemoteChanged(proposal.SourceRevision, remote.Value.ModifiedGmt))
            return await UpdateAsync(proposal, actor, SeoRemediationProposalState.Conflict, "WordPress content changed after this proposal was generated.", cancellationToken);

        _proposals[proposalId] = proposal with { State = SeoRemediationProposalState.Applying, Error = string.Empty };
        var update = BuildUpdate(remote.Value, proposal);
        var mutation = await editor.UpdateAsync(siteId, update, cancellationToken);
        if (mutation.IsFailure) return await UpdateAsync(proposal, actor, SeoRemediationProposalState.Failed, mutation.Error.Message, cancellationToken);

        WordPressSyncViewResult sync;
        try { sync = await synchronization.SynchronizeAsync(siteId, cancellationToken, forceFullRefresh: true); }
        catch (Exception ex) { return await UpdateAsync(proposal, actor, SeoRemediationProposalState.Failed, "WordPress changed, but synchronization/re-read verification failed: " + ex.Message, cancellationToken); }
        if (!sync.IsSuccess) return await UpdateAsync(proposal, actor, SeoRemediationProposalState.Failed, "WordPress changed, but synchronization/re-read verification failed: " + sync.Message, cancellationToken);
        var verified = await editor.GetAsync(siteId, proposal.ContentType, proposal.ContentId, cancellationToken);
        if (verified.IsFailure || !string.Equals(GetField(verified.Value, proposal.Field), proposal.SuggestedValue, StringComparison.Ordinal))
            return await UpdateAsync(proposal, actor, SeoRemediationProposalState.Failed, "WordPress changed, but the persisted re-read did not verify the intended value.", cancellationToken);
        return await RecordVerifiedAsync(proposal, actor, current, false, isUndo, cancellationToken);
    }

    private async Task<SeoRemediationApplyResult> RecordVerifiedAsync(SeoRemediationProposal proposal, Guid actor, string before, bool noOp, bool isUndo, CancellationToken cancellationToken)
    {
        var entry = new SeoRemediationAuditEntry(Guid.Empty, proposal.ProposalId, proposal.CorrelationId, proposal.SiteId, actor,
            DateTimeOffset.UtcNow, proposal.ContentId, proposal.ContentType, proposal.Field, before, proposal.SuggestedValue,
            proposal.Provider, isUndo ? "Undo" : "Apply", SeoRemediationProposalState.Verified, string.Empty);
        var persistedAudit = await audit.RecordCurrentAsync(AuditCategory, entry.Action, "Succeeded", "WordPressContent",
            $"{entry.SiteId:D}/{entry.ContentType}/{entry.ContentId}", entry.Field.ToString(), new Dictionary<string, string>
            {
                ["proposalId"] = proposal.ProposalId.ToString("D"), ["correlationId"] = proposal.CorrelationId.ToString("D"),
                ["siteId"] = proposal.SiteId.ToString("D"), ["contentId"] = proposal.ContentId.ToString(), ["contentType"] = proposal.ContentType,
                ["field"] = proposal.Field.ToString(), ["beforeDigest"] = Hash(before), ["afterDigest"] = Hash(proposal.SuggestedValue),
                ["beforeValue"] = before, ["afterValue"] = proposal.SuggestedValue,
                ["provider"] = proposal.Provider, ["noOp"] = noOp.ToString(), ["result"] = SeoRemediationProposalState.Verified.ToString()
            }, cancellationToken);
        _proposals[proposal.ProposalId] = proposal with { State = SeoRemediationProposalState.Verified, Error = string.Empty };
        return new(proposal.ProposalId, SeoRemediationProposalState.Verified, noOp ? "Already persisted; verified without another mutation." : "Persisted and verified by authoritative re-read.", noOp, persistedAudit.EventId);
    }

    private async Task<Guid> AuthorizeAsync(Guid siteId, bool requireEdit, CancellationToken cancellationToken)
    {
        var actor = requireEdit ? currentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit) : currentUser.RequireUserId();
        var owned = await dbContext.Sites.AsNoTracking().AnyAsync(x => x.Id == siteId && x.OwnerUserId == actor, cancellationToken);
        if (!owned) throw new UnauthorizedAccessException("The site is unavailable for the current account.");
        await entitlementEnforcement.RequireBooleanCapabilityAsync(actor, EntitlementDefinitionCatalog.PremiumSeo, cancellationToken);
        return actor;
    }

    private async Task<SeoRemediationTarget[]> ResolveTargetsAsync(Guid siteId, CancellationToken cancellationToken)
    {
        var records = await dbContext.WordPressContentRecords.AsNoTracking().Where(x => x.SiteId == siteId && x.IsAvailable)
            .OrderByDescending(x => x.ModifiedAtUtc).Take(25).ToArrayAsync(cancellationToken);
        return records.SelectMany(x => new[]
        {
            new SeoRemediationTarget(x.WordPressId, x.ContentType, SeoRemediationField.Title),
            new SeoRemediationTarget(x.WordPressId, x.ContentType, SeoRemediationField.MetaDescription)
        }).Take(MaximumTargets).ToArray();
    }

    private SeoRemediationProposal StoreFailure(Guid siteId, SeoRemediationTarget target, string? error)
    {
        var proposal = new SeoRemediationProposal(Guid.NewGuid(), Guid.NewGuid(), siteId, target.ContentId, NormalizeType(target.ContentType),
            target.Field, string.Empty, string.Empty, string.Empty, 0, SafetyFor(target.Field), SeoRemediationProposalState.Failed,
            null, DateTimeOffset.UtcNow, string.Empty, string.Empty, Sanitize(error));
        _proposals[proposal.ProposalId] = proposal;
        return proposal;
    }

    private async Task<SeoRemediationApplyResult> UpdateAsync(SeoRemediationProposal proposal, Guid actor, SeoRemediationProposalState state, string message, CancellationToken cancellationToken)
    {
        _proposals[proposal.ProposalId] = proposal with { State = state, Error = message };
        var persisted = await audit.RecordCurrentAsync(AuditCategory, "Apply", state.ToString(), "WordPressContent",
            $"{proposal.SiteId:D}/{proposal.ContentType}/{proposal.ContentId}", proposal.Field.ToString(), new Dictionary<string, string>
            {
                ["proposalId"] = proposal.ProposalId.ToString("D"), ["correlationId"] = proposal.CorrelationId.ToString("D"),
                ["siteId"] = proposal.SiteId.ToString("D"), ["contentId"] = proposal.ContentId.ToString(), ["contentType"] = proposal.ContentType,
                ["field"] = proposal.Field.ToString(), ["beforeValue"] = proposal.CurrentValue,
                ["afterValue"] = proposal.SuggestedValue, ["provider"] = proposal.Provider,
                ["result"] = state.ToString(), ["failureReason"] = Sanitize(message), ["actorId"] = actor.ToString("D")
            }, cancellationToken);
        return new(proposal.ProposalId, state, message, false, persisted.EventId);
    }

    private static WordPressContentUpdateRequest BuildUpdate(WordPressEditableContent remote, SeoRemediationProposal proposal) => new(
        remote.ContentType, remote.Id,
        proposal.Field == SeoRemediationField.Title ? proposal.SuggestedValue : remote.Title,
        remote.Slug, remote.Status, remote.Content,
        proposal.Field == SeoRemediationField.MetaDescription ? proposal.SuggestedValue : remote.Excerpt,
        remote.DateGmt, remote.FeaturedMediaId, remote.CategoryIds, remote.TagIds, remote.Template,
        remote.CommentStatus, remote.PingStatus, remote.Format, remote.Sticky, remote.ModifiedGmt);

    private static string GetField(WordPressEditableContent content, SeoRemediationField field) => field switch
    {
        SeoRemediationField.Title => content.Title,
        SeoRemediationField.MetaDescription => content.Excerpt,
        _ => string.Empty
    };

    private static string BuildPrompt(WordPressEditableContent content, SeoRemediationField field, string current)
    {
        var source = SeoRuleEngine.PlainText(content.Content);
        if (source.Length > MaximumSourceLength) source = source[..MaximumSourceLength];
        return $"Improve the WordPress {field} for SEO without changing facts. Current value: {current}\nContent: {source}";
    }

    private static bool TryParse(string content, SeoRemediationField field, string current, out ParsedSuggestion parsed, out string error)
    {
        parsed = default!;
        error = "The AI provider returned malformed proposal data.";
        try
        {
            using var json = JsonDocument.Parse(content);
            var root = json.RootElement;
            var value = root.GetProperty("suggestedValue").GetString()?.Trim() ?? string.Empty;
            var reason = root.GetProperty("reason").GetString()?.Trim() ?? string.Empty;
            var confidence = root.GetProperty("confidence").GetDecimal();
            var limit = field == SeoRemediationField.Title ? 100 : MaximumSuggestionLength;
            if (string.IsNullOrWhiteSpace(value) || value.Length > limit || string.IsNullOrWhiteSpace(reason) ||
                confidence is < 0 or > 1 || string.Equals(value, current, StringComparison.Ordinal)) return false;
            parsed = new(value, reason.Length > 500 ? reason[..500] : reason, confidence);
            error = string.Empty;
            return true;
        }
        catch (Exception) { return false; }
    }

    internal static bool TryValidateSuggestion(string content, SeoRemediationField field, string current,
        out string suggestedValue, out string reason, out decimal confidence, out string error)
    {
        var valid = TryParse(content, field, current, out var parsed, out error);
        suggestedValue = valid ? parsed.SuggestedValue : string.Empty;
        reason = valid ? parsed.Reason : string.Empty;
        confidence = valid ? parsed.Confidence : 0;
        return valid;
    }

    internal static SeoRemediationSafetyClass SafetyFor(SeoRemediationField field) => field switch
    {
        SeoRemediationField.Title or SeoRemediationField.MetaDescription => SeoRemediationSafetyClass.SafeAutomatic,
        SeoRemediationField.ImageAltText => SeoRemediationSafetyClass.Unsupported,
        _ => SeoRemediationSafetyClass.ReviewRequired
    };

    private static string NormalizeType(string type) => string.Equals(type, "page", StringComparison.OrdinalIgnoreCase) ? "page" : "post";
    private static string Sanitize(string? error) => string.IsNullOrWhiteSpace(error) ? "The operation failed." : error.Replace("Bearer ", "Bearer [REDACTED]", StringComparison.OrdinalIgnoreCase)[..Math.Min(error.Length, 500)];
    private static string Hash(string value) => Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(value ?? string.Empty)));
    private static SeoRemediationAuditEntry? ToHistory(SecurityAuditRecord record)
    {
        try
        {
            var metadata = record.Metadata;
            return new(record.EventId, Guid.Parse(metadata["proposalId"]), Guid.Parse(metadata["correlationId"]),
                Guid.Parse(metadata["siteId"]), record.ActorUserId ?? Guid.Empty, new DateTimeOffset(record.OccurredAtUtc, TimeSpan.Zero),
                int.Parse(metadata["contentId"]), metadata["contentType"], Enum.Parse<SeoRemediationField>(metadata["field"]),
                metadata["beforeValue"], metadata["afterValue"], metadata.GetValueOrDefault("provider", string.Empty),
                record.Action, Enum.Parse<SeoRemediationProposalState>(metadata.GetValueOrDefault("result", "Failed")), metadata.GetValueOrDefault("failureReason", string.Empty));
        }
        catch (Exception) { return null; }
    }
    private sealed record ParsedSuggestion(string SuggestedValue, string Reason, decimal Confidence);
}

public enum SeoRemediationField { Title, MetaDescription, ImageAltText, InternalLink }
public enum SeoRemediationSafetyClass { SafeAutomatic, ReviewRequired, Unsupported }
public enum SeoRemediationProposalState { NotGenerated, AiSuggested, Selected, Applying, Verified, Failed, Conflict, NeedsReview }
public enum SeoAiProviderState { Ready, NotConfigured, Unavailable }
public sealed record SeoAiProviderReadiness(SeoAiProviderState State, string Provider, string Message);
public sealed record SeoRemediationGenerationResult(SeoAiProviderReadiness Readiness, IReadOnlyList<SeoRemediationProposal> Proposals);
public sealed record SeoRemediationTarget(int ContentId, string ContentType, SeoRemediationField Field);
public sealed record SeoRemediationProposal(Guid ProposalId, Guid CorrelationId, Guid SiteId, int ContentId, string ContentType,
    SeoRemediationField Field, string CurrentValue, string SuggestedValue, string Reason, decimal Confidence,
    SeoRemediationSafetyClass SafetyClass, SeoRemediationProposalState State, DateTimeOffset? SourceRevision,
    DateTimeOffset GeneratedAtUtc, string Provider, string RuntimeModel, string Error);
public sealed record SeoRemediationApplyResult(Guid ProposalId, SeoRemediationProposalState State, string Message, bool IsNoOp, Guid? AuditId);
public sealed record SeoRemediationBulkResult(int Selected, int Succeeded, int Failed, int Conflicted, int ReviewRequired, IReadOnlyList<SeoRemediationApplyResult> Items);
public sealed record SeoRemediationAuditEntry(Guid AuditId, Guid ProposalId, Guid CorrelationId, Guid SiteId, Guid ActorUserId,
    DateTimeOffset OccurredAtUtc, int ContentId, string ContentType, SeoRemediationField Field, string BeforeValue,
    string AfterValue, string Provider, string Action, SeoRemediationProposalState Result, string FailureReason);
