using AIWordPressManager.Application.Abstractions.Persistence;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Application.Changes;
using AIWordPressManager.Application.Common.Results;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using System.Net;
using System.Text.RegularExpressions;

namespace AIWordPressManager.Persistence.Changes;

public sealed class ApprovedChangeExecutionService(
    AppDbContext dbContext,
    ISuggestedChangeService changes,
    IWordPressPostEditorService editor,
    IDatabaseBackupService databaseBackupService) : IApprovedChangeExecutionService
{
    private static readonly HashSet<string> Supported = ["SetTitle", "SetSlug", "SetExcerpt", "SetStatus", "SetContent"];

    public async Task<IReadOnlyList<ApprovedChangeExecutionItem>> GetApprovedQueueAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var rows = await dbContext.SuggestedChanges.AsNoTracking()
            .Where(x => x.SiteId == siteId && (x.ApprovalStatus == "Pending" || x.ApprovalStatus == "Approved"))
            .OrderBy(x => x.RiskLevel).ThenBy(x => x.CreatedAtUtc)
            .ToListAsync(cancellationToken);
        var recordIds = rows.Select(x => Guid.TryParse(x.ObjectId, out var id) ? id : Guid.Empty).Where(x => x != Guid.Empty).Distinct().ToArray();
        var content = await dbContext.WordPressContentRecords.AsNoTracking().Where(x => recordIds.Contains(x.Id)).ToDictionaryAsync(x => x.Id, cancellationToken);
        return rows.Select(x => BuildItem(x, content)).ToArray();
    }

    public async Task<Result<ExecutablePreparationResult>> PrepareExecutableValuesAsync(Guid siteId, IReadOnlyCollection<Guid> changeIds, CancellationToken cancellationToken = default)
    {
        if (changeIds.Count == 0)
            return Result.Failure<ExecutablePreparationResult>(Error.Validation("Select at least one change to prepare."));

        var changesToPrepare = await dbContext.SuggestedChanges
            .Where(x => x.SiteId == siteId && changeIds.Contains(x.Id))
            .ToListAsync(cancellationToken);

        var recordIds = changesToPrepare
            .Select(x => Guid.TryParse(x.ObjectId, out var id) ? id : Guid.Empty)
            .Where(x => x != Guid.Empty)
            .Distinct()
            .ToArray();

        var content = await dbContext.WordPressContentRecords
            .AsNoTracking()
            .Where(x => x.SiteId == siteId && recordIds.Contains(x.Id))
            .ToDictionaryAsync(x => x.Id, cancellationToken);

        var prepared = 0;
        var alreadyExecutable = 0;
        var unsupported = 0;
        var now = DateTime.UtcNow;

        foreach (var change in changesToPrepare)
        {
            if (Supported.Contains(change.ChangeType) && HasConcreteValue(change.ChangeType, change.ProposedValue))
            {
                alreadyExecutable++;
                continue;
            }

            if (!Guid.TryParse(change.ObjectId, out var recordId) || !content.TryGetValue(recordId, out var row))
            {
                unsupported++;
                continue;
            }

            var normalized = Normalize(change, row);
            if (normalized is null)
            {
                unsupported++;
                continue;
            }

            change.PrepareForExecution(normalized.Value.ChangeType, normalized.Value.ProposedValue,
                normalized.Value.RiskLevel, requiresBackup: true, requiresStaging: false, now);
            prepared++;
        }

        if (prepared > 0)
            await dbContext.SaveChangesAsync(cancellationToken);

        return Result.Success(new ExecutablePreparationResult(changeIds.Count, prepared, alreadyExecutable, unsupported));
    }

    public Task<Result<ChangeExecutionBatchResult>> ExecuteAsync(Guid siteId, IReadOnlyCollection<Guid> changeIds, IProgress<(int Percent, string Step)>? progress = null, CancellationToken cancellationToken = default)
        => ProcessAsync(siteId, changeIds, rollback: false, progress, cancellationToken);

    public Task<Result<ChangeExecutionBatchResult>> RollbackAsync(Guid siteId, IReadOnlyCollection<Guid> changeIds, IProgress<(int Percent, string Step)>? progress = null, CancellationToken cancellationToken = default)
        => ProcessAsync(siteId, changeIds, rollback: true, progress, cancellationToken);

    private async Task<Result<ChangeExecutionBatchResult>> ProcessAsync(Guid siteId, IReadOnlyCollection<Guid> ids, bool rollback, IProgress<(int Percent, string Step)>? progress, CancellationToken cancellationToken)
    {
        if (ids.Count == 0) return Result.Failure<ChangeExecutionBatchResult>(Error.Validation("Select at least one approved change."));
        var rows = await dbContext.SuggestedChanges.Where(x => x.SiteId == siteId && ids.Contains(x.Id)).ToListAsync(cancellationToken);
        if (rows.Count > 0)
        {
            progress?.Report((1, "Creating a SQLite safety backup before the WordPress operation."));
            await databaseBackupService.CreateBackupAsync(cancellationToken);
        }
        var executed = 0; var failed = 0; var skipped = 0; var verified = 0; var index = 0;
        foreach (var change in rows)
        {
            cancellationToken.ThrowIfCancellationRequested();
            index++;
            progress?.Report(((index - 1) * 100 / rows.Count, $"{(rollback ? "Rolling back" : "Executing")} {index} of {rows.Count}: {change.ChangeType}"));
            if (!CanProcess(change, rollback)) { skipped++; continue; }
            if (!Guid.TryParse(change.ObjectId, out var recordId)) { await changes.SetExecutionStatusAsync(change.Id, "Failed", cancellationToken); failed++; continue; }
            var local = await dbContext.WordPressContentRecords.AsNoTracking().SingleOrDefaultAsync(x => x.Id == recordId && x.SiteId == siteId, cancellationToken);
            if (local is null) { await changes.SetExecutionStatusAsync(change.Id, "Failed", cancellationToken); failed++; continue; }
            try
            {
                await changes.SetExecutionStatusAsync(change.Id, "Executing", cancellationToken);
                var live = await editor.GetAsync(siteId, local.ContentType, local.WordPressId, cancellationToken);
                if (live.IsFailure) { await changes.SetExecutionStatusAsync(change.Id, "Failed", cancellationToken); failed++; continue; }
                var target = rollback ? change.CurrentValue : change.ProposedValue;
                var update = Apply(live.Value, change.ChangeType, target);
                var result = await editor.UpdateAsync(siteId, update, cancellationToken);
                if (result.IsFailure) { await changes.SetExecutionStatusAsync(change.Id, "Failed", cancellationToken); failed++; continue; }
                var check = await editor.GetAsync(siteId, local.ContentType, local.WordPressId, cancellationToken);
                var ok = check.IsSuccess && Verify(check.Value, change.ChangeType, target);
                await changes.SetExecutionStatusAsync(change.Id, rollback ? "RolledBack" : ok ? "Executed" : "Failed", cancellationToken);
                if (ok) { executed++; verified++; } else failed++;
            }
            catch (OperationCanceledException) { throw; }
            catch
            {
                await changes.SetExecutionStatusAsync(change.Id, "Failed", CancellationToken.None);
                failed++;
            }
        }
        progress?.Report((100, rollback ? "Rollback batch completed." : "Execution batch completed."));
        return Result.Success(new ChangeExecutionBatchResult(ids.Count, executed, failed, skipped, verified));
    }

    private static bool CanProcess(SuggestedChange x, bool rollback)
        => x.ObjectType == "Content" && Supported.Contains(x.ChangeType) && !x.RequiresStaging && x.RiskLevel != "High"
           && (!rollback ? x.ApprovalStatus == "Approved" && (x.ExecutionStatus is "NotStarted" or "Failed") : x.ExecutionStatus == "Executed");

    private static ApprovedChangeExecutionItem BuildItem(SuggestedChange x, IReadOnlyDictionary<Guid, WordPressContentRecord> content)
    {
        WordPressContentRecord? row = null;
        var validId = Guid.TryParse(x.ObjectId, out var id) && content.TryGetValue(id, out row);
        var canApprove = x.ApprovalStatus == "Pending";
        var concrete = Supported.Contains(x.ChangeType) && HasConcreteValue(x.ChangeType, x.ProposedValue);
        var can = x.ApprovalStatus == "Approved" && validId && x.ObjectType == "Content" && concrete && !x.RequiresStaging && x.RiskLevel != "High" && x.ExecutionStatus is "NotStarted" or "Failed";
        var route = ResolveRoute(x, validId, concrete);
        var message = x.ApprovalStatus == "Pending" && (!validId || x.ObjectType != "Content" || !Supported.Contains(x.ChangeType) || x.RequiresStaging || x.RiskLevel == "High")
            ? $"AI routed this result to {route.ExecutorName}. Approval is allowed, but WordPress write access remains blocked until the route is executable."
            : !validId ? "The synchronized content record is unavailable."
            : x.ApprovalStatus == "Pending" ? "Review and approve this concrete change before execution."
            : x.RequiresStaging ? $"This change is routed to {route.ExecutorName} and requires staging."
            : x.RiskLevel == "High" ? $"High-risk change routed to {route.ExecutorName}; staging and explicit approval are required."
            : !concrete ? $"AI routed this result to {route.ExecutorName}. Use Prepare selected to generate a concrete executable value when supported."
            : x.ExecutionStatus == "Executed" ? "Executed, verified, and eligible for rollback."
            : "Ready: a database backup, WordPress update, read-back verification, and response log will run automatically.";
        var before = BuildPreview(x.ChangeType, x.CurrentValue, "BEFORE");
        var after = BuildPreview(x.ChangeType, x.ProposedValue, "AFTER");
        return new(x.Id, x.ObjectType, validId ? $"{row!.ContentType} #{row.WordPressId} — {row.Title}" : x.ObjectId, x.ChangeType, x.CurrentValue, x.ProposedValue, x.RiskLevel, x.RequiresBackup, x.RequiresStaging, x.ApprovalStatus, x.ExecutionStatus, canApprove, can, message, route.ExecutorName, route.RouteState, route.ExecutionPlan, before, after);
    }

    private static (string ExecutorName, string RouteState, string ExecutionPlan) ResolveRoute(SuggestedChange change, bool validContent, bool concrete)
    {
        var code = change.ChangeType.Trim().ToUpperInvariant();
        if (Supported.Contains(change.ChangeType) || code.StartsWith("SEO_", StringComparison.Ordinal))
        {
            var state = validContent && concrete && !change.RequiresStaging && change.RiskLevel != "High" ? "Executable" : "Prepare value";
            return ("WordPress Content Executor", state,
                "Create safety backup → GET live WordPress value → POST the exact content update → GET again → verify field value → write API response log.");
        }

        if (code.Contains("ALT", StringComparison.Ordinal) || code.Contains("IMAGE", StringComparison.Ordinal) || code.Contains("MEDIA", StringComparison.Ordinal))
            return ("Media & ALT Executor", "Adapter required",
                "Resolve the exact media record → preview ALT/source change → update the media REST endpoint → verify attachment metadata → capture before/after evidence.");

        if (code.Contains("TOUCH", StringComparison.Ordinal) || code.Contains("TEXT", StringComparison.Ordinal) || code.Contains("OVERFLOW", StringComparison.Ordinal) || code.Contains("CSS", StringComparison.Ordinal))
            return ("Visual CSS Executor", "Staging required",
                "Locate affected selector → generate scoped CSS patch → render desktop/tablet/mobile preview → require staging → publish stylesheet → verify computed style and screenshot diff.");

        if (code.Contains("CONSOLE", StringComparison.Ordinal) || code.Contains("SCRIPT", StringComparison.Ordinal) || code.Contains("PLUGIN", StringComparison.Ordinal))
            return ("Diagnostics Executor", "Manual review",
                "Reproduce the browser error → identify plugin/theme source → produce a bounded fix plan → run only after explicit review and rollback preparation.");

        if (code.Contains("CATEGORY", StringComparison.Ordinal) || code.Contains("TAG", StringComparison.Ordinal) || code.Contains("LINK", StringComparison.Ordinal))
            return ("Taxonomy & Links Executor", "Adapter required",
                "Resolve synchronized IDs → preview relationship changes → submit WordPress REST updates → reload affected objects → verify links and taxonomy assignments.");

        return ("AI Specialist Router", "Manual review",
            "AI must classify the operation, select a bounded executor, generate an exact value, and pass safety validation before WordPress receives any request.");
    }

    private static string BuildPreview(string changeType, string? value, string label)
    {
        var text = value ?? string.Empty;
        if (text.Length > 1200)
            text = text[..1200] + "…";
        return $"{label} • {changeType}{Environment.NewLine}{text}";
    }

    private static (string ChangeType, string ProposedValue, string RiskLevel)? Normalize(SuggestedChange change, WordPressContentRecord row)
    {
        var code = change.ChangeType.Trim().ToUpperInvariant();
        return code switch
        {
            "SEO_TITLE_TOO_LONG" or "TITLE_TOO_LONG" or "SEO_TITLE_TOO_SHORT" or "TITLE_TOO_SHORT"
                => ("SetTitle", BuildSeoTitle(row.Title), "Low"),
            "SEO_MISSING_SLUG" or "MISSING_SLUG" or "SEO_SLUG_TOO_LONG"
                => ("SetSlug", Slugify(row.Title, 70), "Low"),
            "SEO_MISSING_DESCRIPTION" or "SEO_MISSING_DESCRIPTION_SOURCE" or "MISSING_EXCERPT"
                => ("SetExcerpt", BuildExcerpt(row.RenderedContent, row.Title), "Low"),
            "SEO_DESCRIPTION_TOO_LONG" or "DESCRIPTION_TOO_LONG"
                => ("SetExcerpt", BuildExcerpt(row.RenderedExcerpt, row.Title), "Low"),
            "SEO_NO_H1_IN_CONTENT" or "NO_H1_IN_CONTENT" or "MISSING_H1"
                => ("SetContent", InsertPrimaryHeading(row.RenderedContent, row.Title), "Medium"),
            _ => null
        };
    }

    private static bool HasConcreteValue(string changeType, string value)
    {
        if (string.IsNullOrWhiteSpace(value)) return false;
        var text = value.Trim();
        if (text.StartsWith("Review and apply", StringComparison.OrdinalIgnoreCase)) return false;
        if (text.StartsWith("Apply a concrete correction", StringComparison.OrdinalIgnoreCase)) return false;
        if (text.StartsWith("Open the related editor", StringComparison.OrdinalIgnoreCase)) return false;
        return changeType switch
        {
            "SetTitle" => text.Length is >= 3 and <= 100,
            "SetSlug" => text.Length is >= 1 and <= 200 && !text.Contains(' '),
            "SetExcerpt" => text.Length >= 20,
            "SetStatus" => text is "publish" or "draft" or "pending" or "private" or "future",
            "SetContent" => text.Length >= 20 && text.Contains("<h1", StringComparison.OrdinalIgnoreCase),
            _ => false
        };
    }

    private static string BuildSeoTitle(string title)
    {
        var clean = CleanText(title);
        if (clean.Length <= 60) return clean;
        var cut = clean[..60];
        var space = cut.LastIndexOf(' ');
        return (space > 35 ? cut[..space] : cut).Trim(' ', '-', ',', '.', ':', ';');
    }

    private static string BuildExcerpt(string? html, string fallbackTitle)
    {
        var clean = CleanText(html);
        if (clean.Length < 20) clean = CleanText(fallbackTitle);
        if (clean.Length <= 155) return clean;
        var cut = clean[..155];
        var space = cut.LastIndexOf(' ');
        return (space > 100 ? cut[..space] : cut).Trim(' ', '-', ',', '.', ':', ';') + ".";
    }


    private static string InsertPrimaryHeading(string? html, string title)
    {
        var content = html ?? string.Empty;
        if (Regex.IsMatch(content, @"<h1\b", RegexOptions.IgnoreCase))
            return content;

        var safeTitle = WebUtility.HtmlEncode(CleanText(title));
        if (string.IsNullOrWhiteSpace(safeTitle))
            safeTitle = "Page title";

        return $"<h1>{safeTitle}</h1>\n{content}";
    }

    private static string Slugify(string value, int maxLength)
    {
        var normalized = CleanText(value).ToLowerInvariant();
        normalized = Regex.Replace(normalized, @"[^\p{L}\p{Nd}]+", "-").Trim('-');
        if (normalized.Length <= maxLength) return normalized;
        var cut = normalized[..maxLength];
        var dash = cut.LastIndexOf('-');
        return (dash > maxLength / 2 ? cut[..dash] : cut).Trim('-');
    }

    private static string CleanText(string? html)
        => Regex.Replace(WebUtility.HtmlDecode(html ?? string.Empty), "<[^>]+>", " ")
            .Replace("\r", " ").Replace("\n", " ").Trim();

    private static WordPressContentUpdateRequest Apply(WordPressEditableContent live, string type, string value)
        => new(live.ContentType, live.Id, type == "SetTitle" ? value : live.Title, type == "SetSlug" ? value : live.Slug, type == "SetStatus" ? value : live.Status, type == "SetContent" ? value : live.Content, type == "SetExcerpt" ? value : live.Excerpt, live.DateGmt, live.FeaturedMediaId, live.CategoryIds, live.TagIds, live.Template, live.CommentStatus, live.PingStatus, live.Format, live.Sticky);

    private static bool Verify(WordPressEditableContent live, string type, string expected)
        => type switch { "SetTitle" => Same(live.Title, expected), "SetSlug" => Same(live.Slug, expected), "SetExcerpt" => Same(live.Excerpt, expected), "SetStatus" => Same(live.Status, expected), "SetContent" => SameHtml(live.Content, expected), _ => false };
    private static bool Same(string a, string b) => string.Equals(a.Trim(), b.Trim(), StringComparison.Ordinal);
    private static bool SameHtml(string a, string b)
        => string.Equals(Regex.Replace(a ?? string.Empty, @"\s+", " ").Trim(), Regex.Replace(b ?? string.Empty, @"\s+", " ").Trim(), StringComparison.Ordinal);
}
