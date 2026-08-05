using Xunit;

[assembly: CollectionBehavior(DisableTestParallelization = true)]

namespace AIWordPressManager.Tests;

[CollectionDefinition(Name, DisableParallelization = true)]
public sealed class WorkflowTestCollection
{
    public const string Name = "SQLite workflow tests";
}
