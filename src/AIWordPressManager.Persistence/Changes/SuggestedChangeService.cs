using System.Security.Cryptography;
using System.Text;
using System.Net;
using System.Text.RegularExpressions;
using AIWordPressManager.Application.Changes;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Changes;

public sealed class SuggestedChangeService(AppDbContext dbContext, IAiSuggestionProvider aiSuggestionProvider) : ISuggestedChangeService
{
    public async Task<IReadOnlyList<SuggestedChangeItem>> GetAsync(Guid siteId, string? approvalStatus = null, CancellationToken cancellationToken = default)
    {
        var query = dbContext.SuggestedChanges.AsNoTracking().Where(x => x.SiteId == siteId);
        if (!string.IsNullOrWhiteSpace(approvalStatus)) query = query.Where(x => x.ApprovalStatus == approvalStatus);
        return await query.OrderByDescending(x => x.CreatedAtUtc).Select(x => new SuggestedChangeItem(
            x.Id, x.SourceType, x.ObjectType, x.ObjectId, x.ChangeType, x.CurrentValue, x.ProposedValue,
            x.Reason, x.Confidence, x.RiskLevel, x.RequiresBackup, x.RequiresStaging,
            x.ApprovalStatus, x.ExecutionStatus, x.CreatedAtUtc)).ToListAsync(cancellationToken);
    }

    public async Task<SuggestedChangeGenerationResult> GenerateFromLocalInsightsAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var candidates = new List<Candidate>();
        var contentRows = await dbContext.WordPressContentRecords.AsNoTracking()
            .Where(x => x.SiteId == siteId && x.IsAvailable)
            .ToDictionaryAsync(x => x.Id, cancellationToken);

        var seo = await dbContext.SeoAuditIssues.AsNoTracking().Where(x => x.SiteId == siteId).ToListAsync(cancellationToken);
        foreach (var issue in seo)
        {
            contentRows.TryGetValue(issue.ContentRecordId, out var row);
            candidates.Add(CreateContentCandidate("SEO Audit", issue.IssueCode, issue.Severity, issue.ContentRecordId, issue.Title, issue.Description, row));
        }

        var content = await dbContext.ContentAuditIssues.AsNoTracking().Where(x => x.SiteId == siteId).ToListAsync(cancellationToken);
        foreach (var issue in content)
        {
            contentRows.TryGetValue(issue.ContentRecordId, out var row);
            candidates.Add(CreateContentCandidate("Content Audit", issue.IssueCode, issue.Severity, issue.ContentRecordId, issue.Title, issue.Description, row));
        }

        var broken = await dbContext.BrokenLinks.AsNoTracking().Where(x => x.SiteId == siteId && (x.Status == "Broken" || x.Status == "Error")).ToListAsync(cancellationToken);
        foreach (var link in broken)
        {
            candidates.Add(new("Broken Links", "Content", link.ContentRecordId.ToString(), "ReplaceBrokenLink",
                link.TargetUrl, "Remove the broken hyperlink while preserving its anchor text, or replace it only with a URL that passes verification.", $"Link check returned {link.StatusCode?.ToString() ?? link.Status}.",
                0.94, "Medium", false, false));
        }

        var categories = await dbContext.WordPressCategoryRecords.AsNoTracking().Where(x => x.SiteId == siteId && x.IsAvailable && x.PostCount < 3).ToListAsync(cancellationToken);
        foreach (var category in categories)
        {
            var proposed = category.PostCount == 0 ? "Review for merge, removal, or a supporting content plan." : "Add supporting posts or merge with a related category.";
            candidates.Add(new("Category Planner", "Category", category.WordPressId.ToString(), category.PostCount == 0 ? "ReviewEmptyCategory" : "StrengthenCategory",
                $"{category.Name} ({category.PostCount} posts)", proposed, "The offline category analysis identified a weak taxonomy node.",
                0.7, category.PostCount == 0 ? "Medium" : "Low", category.PostCount == 0, false));
        }

        if (!await aiSuggestionProvider.IsConfiguredAsync(cancellationToken))
            throw new InvalidOperationException("The recommendation engine is unavailable.");

        var aiInputs = candidates.Select(x => new AiSuggestionInput(
            x.SourceType, x.ObjectType, x.ObjectId, x.ChangeType, x.CurrentValue,
            x.ProposedValue, x.Reason, x.RiskLevel)).ToArray();
        var aiResults = await aiSuggestionProvider.ImproveSuggestionsAsync(aiInputs, cancellationToken);
        var aiLookup = aiResults
            .GroupBy(x => (x.ObjectId, x.ChangeType))
            .ToDictionary(x => x.Key, x => x.First());
        candidates = candidates.Select(candidate =>
        {
            if (!aiLookup.TryGetValue((candidate.ObjectId, candidate.ChangeType), out var improved)) return candidate;
            return candidate with
            {
                ProposedValue = improved.ProposedValue,
                Reason = improved.Reason,
                Confidence = improved.Confidence,
                RiskLevel = NormalizeRisk(improved.RiskLevel)
            };
        }).ToList();

        var existingFingerprintValues = await dbContext.SuggestedChanges
            .AsNoTracking()
            .Where(x => x.SiteId == siteId)
            .Select(x => x.Fingerprint)
            .ToListAsync(cancellationToken);
        var existingFingerprints = existingFingerprintValues.ToHashSet(StringComparer.Ordinal);
        var now = DateTime.UtcNow;
        var created = 0;
        foreach (var candidate in candidates)
        {
            var fingerprint = Fingerprint(candidate);
            if (!existingFingerprints.Add(fingerprint)) continue;
            dbContext.SuggestedChanges.Add(new SuggestedChange(siteId, fingerprint, candidate.SourceType, candidate.ObjectType,
                candidate.ObjectId, candidate.ChangeType, candidate.CurrentValue, candidate.ProposedValue,
                candidate.Reason, candidate.Confidence, NormalizeRisk(candidate.RiskLevel), candidate.RequiresBackup,
                candidate.RequiresStaging, now));
            created++;
        }
        if (created > 0) await dbContext.SaveChangesAsync(cancellationToken);
        var pending = await dbContext.SuggestedChanges.CountAsync(x => x.SiteId == siteId && x.ApprovalStatus == "Pending", cancellationToken);
        return new(created, candidates.Count - created, pending);
    }

    public async Task<VisualSuggestionGenerationResult> CreateFromVisualInspectionAsync(
        Guid siteId,
        IReadOnlyCollection<VisualSuggestionInput> inputs,
        CancellationToken cancellationToken = default)
    {
        var candidates = new List<Candidate>();
        foreach (var input in inputs)
        {
            var objectId = input.ViewportName;
            if (input.HorizontalOverflow)
                candidates.Add(new("Visual Inspector", "VisualViewport", objectId, "FixHorizontalOverflow",
                    "The page width exceeds the viewport.",
                    $"Constrain overflowing elements at {input.ViewportName} to max-width:100%, remove fixed widths wider than the viewport, and verify that document width equals viewport width.",
                    $"{input.PageTitle}: horizontal overflow was measured in the {input.ViewportName} capture.", 0.96, "Medium", true, true));
            if (input.MissingAltImages > 0)
                candidates.Add(new("Visual Inspector", "VisualViewport", objectId, "AddMissingAltText",
                    $"{input.MissingAltImages} images have missing or empty ALT text.",
                    $"Review the {input.MissingAltImages} affected images in the {input.ViewportName} page and add concise, factual ALT text describing each visible image and its purpose.",
                    "The visual DOM inspection found images without accessible alternative text.", 0.94, "Low", false, false));
            if (input.BrokenImages > 0)
                candidates.Add(new("Visual Inspector", "VisualViewport", objectId, "RepairBrokenImages",
                    $"{input.BrokenImages} images failed to render.",
                    $"Replace or correct the source URL for the {input.BrokenImages} broken images, preserve their dimensions, and verify HTTP 200 plus naturalWidth greater than zero.",
                    "Broken image elements were confirmed after the page completed loading.", 0.97, "Medium", true, false));
            if (input.SmallTextElements > 0)
                candidates.Add(new("Visual Inspector", "VisualViewport", objectId, "IncreaseSmallText",
                    $"{input.SmallTextElements} visible leaf elements use text below 12px.",
                    $"Raise the affected {input.ViewportName} body text to at least 14px while preserving hierarchy, line height, and responsive wrapping.",
                    "The computed-style scan detected text that may be difficult to read.", 0.86, "Low", false, false));
            if (input.SmallTouchTargets > 0)
                candidates.Add(new("Visual Inspector", "VisualViewport", objectId, "IncreaseTouchTargets",
                    $"{input.SmallTouchTargets} interactive elements are smaller than 44×44px.",
                    $"Increase the clickable area of the affected {input.ViewportName} controls to at least 44×44px using padding or min-size without changing their labels.",
                    "The responsive inspection found interactive targets below the recommended touch size.", 0.92, "Low", false, false));
            if (input.ConsoleErrors.Count > 0)
                candidates.Add(new("Visual Inspector", "VisualViewport", objectId, "ResolveConsoleErrors",
                    string.Join(Environment.NewLine, input.ConsoleErrors.Take(5)),
                    $"Investigate and resolve the {input.ConsoleErrors.Count} captured browser errors, beginning with the first reproducible error; do not suppress errors without fixing their source.",
                    "JavaScript or page errors were emitted while loading the inspected page.", 0.9, "Medium", true, true));
        }

        var existingValues = await dbContext.SuggestedChanges.AsNoTracking()
            .Where(x => x.SiteId == siteId)
            .Select(x => x.Fingerprint)
            .ToListAsync(cancellationToken);
        var existing = existingValues.ToHashSet(StringComparer.Ordinal);
        var now = DateTime.UtcNow;
        var created = 0;
        foreach (var candidate in candidates)
        {
            var fingerprint = Fingerprint(candidate);
            if (!existing.Add(fingerprint)) continue;
            dbContext.SuggestedChanges.Add(new SuggestedChange(siteId, fingerprint, candidate.SourceType, candidate.ObjectType,
                candidate.ObjectId, candidate.ChangeType, candidate.CurrentValue, candidate.ProposedValue,
                candidate.Reason, candidate.Confidence, NormalizeRisk(candidate.RiskLevel), candidate.RequiresBackup,
                candidate.RequiresStaging, now));
            created++;
        }
        if (created > 0) await dbContext.SaveChangesAsync(cancellationToken);
        return new(created, candidates.Count - created);
    }

    public async Task SetApprovalStatusAsync(Guid changeId, string status, CancellationToken cancellationToken = default)
    {
        var change = await dbContext.SuggestedChanges.SingleAsync(x => x.Id == changeId, cancellationToken);
        var now = DateTime.UtcNow;
        switch (status)
        {
            case "Approved": change.Approve(now); break;
            case "Rejected": change.Reject(now); break;
            case "Pending": change.ReturnToPending(now); break;
            default: throw new ArgumentOutOfRangeException(nameof(status), status, "Unsupported approval status.");
        }
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task SetExecutionStatusAsync(Guid changeId, string status, CancellationToken cancellationToken = default)
    {
        var change = await dbContext.SuggestedChanges.SingleAsync(x => x.Id == changeId, cancellationToken);
        var now = DateTime.UtcNow;
        switch (status)
        {
            case "Executing": change.MarkExecuting(now); break;
            case "Executed": change.MarkExecuted(now); break;
            case "Failed": change.MarkExecutionFailed(now); break;
            case "RolledBack": change.MarkRolledBack(now); break;
            case "NotStarted": break;
            default: throw new ArgumentOutOfRangeException(nameof(status), status, "Unsupported execution status.");
        }
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    private static Candidate CreateContentCandidate(string source, string code, string severity, Guid recordId, string title, string description, WordPressContentRecord? row)
    {
        if (row is null)
            return new(source, "Content", recordId.ToString(), code, title, BuildActionableProposal(code, row, title), description, 0.65, severity, false, false);

        return code switch
        {
            "SEO_TITLE_TOO_LONG" or "TITLE_TOO_LONG" => new(source, "Content", recordId.ToString(), "SetTitle", row.Title, TruncateWords(row.Title, 60), description, 0.82, "Low", true, false),
            "SEO_MISSING_SLUG" or "MISSING_SLUG" => new(source, "Content", recordId.ToString(), "SetSlug", row.Slug, Slugify(row.Title, 70), description, 0.9, "Low", true, false),
            "SEO_SLUG_TOO_LONG" => new(source, "Content", recordId.ToString(), "SetSlug", row.Slug, Slugify(row.Title, 70), description, 0.78, "Low", true, false),
            "SEO_MISSING_DESCRIPTION_SOURCE" or "MISSING_EXCERPT" => new(source, "Content", recordId.ToString(), "SetExcerpt", row.RenderedExcerpt, TruncateWords(PlainText(row.RenderedContent), 155), description, 0.76, "Low", true, false),
            "SEO_DESCRIPTION_TOO_LONG" => new(source, "Content", recordId.ToString(), "SetExcerpt", row.RenderedExcerpt, TruncateWords(PlainText(row.RenderedExcerpt), 155), description, 0.8, "Low", true, false),
            _ => new(source, "Content", recordId.ToString(), code, title, BuildActionableProposal(code, row, title), description, severity == "High" ? 0.82 : 0.68, severity, severity == "High", false)
        };
    }

    private static string BuildActionableProposal(string code, WordPressContentRecord? row, string title) => code switch
    {
        "SEO_MISSING_H1" or "MISSING_H1" => $"Add exactly one H1 heading using: {row?.Title ?? title}",
        "SEO_MULTIPLE_H1" or "MULTIPLE_H1" => "Keep the first H1 and convert every additional H1 heading to H2 without changing the wording.",
        "SEO_THIN_CONTENT" or "THIN_CONTENT" => "Expand the article with a clear introduction, three topic-specific H2 sections, practical examples, and a concise conclusion; preserve all verified facts.",
        "SEO_MISSING_ALT" or "MISSING_ALT_TEXT" => "Add descriptive alt text that states what the image visibly contains and its purpose on the page, without keyword stuffing.",
        "SEO_BROKEN_INTERNAL_LINK" => "Remove the broken hyperlink while preserving its anchor text until a verified internal replacement is available.",
        "SEO_MISSING_CANONICAL" => "Set the canonical URL to the current public permalink after confirming that the page is the preferred indexable version.",
        _ => $"Apply a concrete correction for {code}: preserve the existing content, change only the affected field, preview the before/after result, then submit it for approval."
    };

    private static string PlainText(string? html) => Regex.Replace(WebUtility.HtmlDecode(html ?? string.Empty), "<[^>]+>", " ").Replace("\r", " ").Replace("\n", " ").Trim();
    private static string TruncateWords(string value, int maxLength)
    {
        var clean = Regex.Replace(value ?? string.Empty, @"\s+", " ").Trim();
        if (clean.Length <= maxLength) return clean;
        var cut = clean[..maxLength];
        var lastSpace = cut.LastIndexOf(' ');
        return (lastSpace > maxLength / 2 ? cut[..lastSpace] : cut).Trim(' ', '-', ',', '.', ':', ';');
    }

    private static string Slugify(string value, int maxLength)
    {
        var normalized = value.ToLowerInvariant();
        normalized = Regex.Replace(normalized, @"[^\p{L}\p{Nd}]+", "-").Trim('-');
        return TruncateWords(normalized.Replace('-', ' '), maxLength).Replace(' ', '-').Trim('-');
    }

    private static string NormalizeRisk(string value) => value.Equals("High", StringComparison.OrdinalIgnoreCase) ? "High" : value.Equals("Medium", StringComparison.OrdinalIgnoreCase) ? "Medium" : "Low";
    private static string Fingerprint(Candidate candidate)
    {
        var input = $"{candidate.SourceType}|{candidate.ObjectType}|{candidate.ObjectId}|{candidate.ChangeType}|{candidate.CurrentValue}|{candidate.ProposedValue}";
        return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(input))).ToLowerInvariant();
    }
    private sealed record Candidate(string SourceType, string ObjectType, string ObjectId, string ChangeType, string CurrentValue, string ProposedValue, string Reason, double Confidence, string RiskLevel, bool RequiresBackup, bool RequiresStaging);
}
