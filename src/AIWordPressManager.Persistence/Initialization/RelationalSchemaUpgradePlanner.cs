using System.Text.RegularExpressions;

namespace AIWordPressManager.Persistence.Initialization;

/// <summary>
/// Selects only the provider-generated DDL needed to create tables that are absent from an
/// existing database. The full create script is produced by the active EF Core provider, so
/// column types, identifier quoting and provider-specific syntax stay provider-native.
/// </summary>
public static class RelationalSchemaUpgradePlanner
{
    private const string QualifiedIdentifier =
        "(?:(?:\\[[^\\]]+\\]|\"[^\"]+\"|`[^`]+`|[A-Za-z0-9_]+)\\.)?(?:\\[(?<table>[^\\]]+)\\]|\"(?<table>[^\"]+)\"|`(?<table>[^`]+)`|(?<table>[A-Za-z0-9_]+))";

    private static readonly Regex BatchSeparatorRegex = new(
        @"^\s*GO\s*$",
        RegexOptions.IgnoreCase | RegexOptions.Multiline | RegexOptions.CultureInvariant);

    private static readonly Regex CreateTableRegex = new(
        $@"^\s*CREATE\s+TABLE\s+{QualifiedIdentifier}\s*\(",
        RegexOptions.IgnoreCase | RegexOptions.Singleline | RegexOptions.CultureInvariant);

    private static readonly Regex CreateIndexRegex = new(
        $@"^\s*CREATE(?:\s+UNIQUE)?\s+INDEX\s+.+?\s+ON\s+{QualifiedIdentifier}(?:\s|\()",
        RegexOptions.IgnoreCase | RegexOptions.Singleline | RegexOptions.CultureInvariant);

    private static readonly Regex AlterTableRegex = new(
        $@"^\s*ALTER\s+TABLE\s+{QualifiedIdentifier}(?:\s|$)",
        RegexOptions.IgnoreCase | RegexOptions.Singleline | RegexOptions.CultureInvariant);

    public static IReadOnlyList<string> SelectMissingTableCommands(
        string createScript,
        IEnumerable<string> existingTables)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(createScript);
        ArgumentNullException.ThrowIfNull(existingTables);

        var existing = new HashSet<string>(
            existingTables.Where(x => !string.IsNullOrWhiteSpace(x)).Select(x => x.Trim()),
            StringComparer.OrdinalIgnoreCase);

        var statements = SplitStatements(createScript);
        var missingTables = new HashSet<string>(StringComparer.OrdinalIgnoreCase);

        foreach (var statement in statements)
        {
            if (TryGetTargetTable(CreateTableRegex, statement, out var table) && !existing.Contains(table))
                missingTables.Add(table);
        }

        if (missingTables.Count == 0)
            return Array.Empty<string>();

        var selected = new List<string>();
        foreach (var statement in statements)
        {
            if (TryGetTargetTable(CreateTableRegex, statement, out var createTable) && missingTables.Contains(createTable) ||
                TryGetTargetTable(CreateIndexRegex, statement, out var indexTable) && missingTables.Contains(indexTable) ||
                TryGetTargetTable(AlterTableRegex, statement, out var alterTable) && missingTables.Contains(alterTable))
            {
                selected.Add(statement.TrimEnd() + ";");
            }
        }

        foreach (var table in missingTables)
        {
            if (!selected.Any(x => TryGetTargetTable(CreateTableRegex, x, out var selectedTable) &&
                                   string.Equals(selectedTable, table, StringComparison.OrdinalIgnoreCase)))
            {
                throw new InvalidOperationException($"EF Core did not generate a CREATE TABLE command for missing table '{table}'.");
            }
        }

        return selected;
    }

    private static IReadOnlyList<string> SplitStatements(string script)
    {
        var withoutBatchSeparators = BatchSeparatorRegex.Replace(script, ";");
        return withoutBatchSeparators
            .Split(';', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .Where(x => !string.IsNullOrWhiteSpace(x))
            .ToArray();
    }

    private static bool TryGetTargetTable(Regex regex, string statement, out string table)
    {
        var match = regex.Match(statement);
        if (!match.Success)
        {
            table = string.Empty;
            return false;
        }

        table = match.Groups["table"].Value;
        return !string.IsNullOrWhiteSpace(table);
    }
}
