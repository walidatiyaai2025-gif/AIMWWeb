using AIWordPressManager.Persistence.Initialization;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class RelationalSchemaUpgradePlannerTests
{
    [Fact]
    public void SelectMissingTableCommands_SqlServer_PreservesOnlyMissingTableCommands()
    {
        const string script = """
            CREATE TABLE [Sites] (
                [Id] uniqueidentifier NOT NULL,
                CONSTRAINT [PK_Sites] PRIMARY KEY ([Id])
            );
            GO
            CREATE TABLE [SiteEmailRecipients] (
                [Id] uniqueidentifier NOT NULL,
                [SiteId] uniqueidentifier NOT NULL,
                CONSTRAINT [PK_SiteEmailRecipients] PRIMARY KEY ([Id])
            );
            GO
            CREATE UNIQUE INDEX [IX_SiteEmailRecipients_SiteId] ON [SiteEmailRecipients] ([SiteId]);
            GO
            CREATE INDEX [IX_Sites_Name] ON [Sites] ([Id]);
            GO
            """;

        var commands = RelationalSchemaUpgradePlanner.SelectMissingTableCommands(script, ["Sites"]);

        commands.Should().HaveCount(2);
        commands[0].Should().StartWith("CREATE TABLE [SiteEmailRecipients]");
        commands[1].Should().Contain("ON [SiteEmailRecipients]");
        commands.Should().NotContain(x => x.Contains("IX_Sites_Name", StringComparison.Ordinal));
    }

    [Fact]
    public void SelectMissingTableCommands_PostgreSql_PreservesQuotedTableAndAlterOrder()
    {
        const string script = """
            CREATE TABLE "EmailOutboxMessages" (
                "Id" uuid NOT NULL,
                CONSTRAINT "PK_EmailOutboxMessages" PRIMARY KEY ("Id")
            );
            CREATE INDEX "IX_EmailOutboxMessages_Status" ON "EmailOutboxMessages" ("Id");
            ALTER TABLE "EmailOutboxMessages" ADD CONSTRAINT "FK_Outbox_Sites" FOREIGN KEY ("Id") REFERENCES "Sites" ("Id");
            """;

        var commands = RelationalSchemaUpgradePlanner.SelectMissingTableCommands(script, ["Sites"]);

        commands.Should().HaveCount(3);
        commands[0].Should().StartWith("CREATE TABLE \"EmailOutboxMessages\"");
        commands[1].Should().StartWith("CREATE INDEX");
        commands[2].Should().StartWith("ALTER TABLE");
    }

    [Fact]
    public void SelectMissingTableCommands_MySql_HandlesBacktickIdentifiers()
    {
        const string script = """
            CREATE TABLE `AccountMailProfiles` (
                `Id` char(36) NOT NULL,
                PRIMARY KEY (`Id`)
            );
            CREATE UNIQUE INDEX `IX_AccountMailProfiles_OwnerUserId` ON `AccountMailProfiles` (`Id`);
            """;

        var commands = RelationalSchemaUpgradePlanner.SelectMissingTableCommands(script, Array.Empty<string>());

        commands.Should().HaveCount(2);
        commands.Should().OnlyContain(x => x.Contains("AccountMailProfiles", StringComparison.Ordinal));
    }

    [Fact]
    public void SelectMissingTableCommands_IsIdempotentWhenAllTablesExist()
    {
        const string script = """
            CREATE TABLE "EmailSchedules" (
                "Id" uuid NOT NULL,
                CONSTRAINT "PK_EmailSchedules" PRIMARY KEY ("Id")
            );
            CREATE INDEX "IX_EmailSchedules_NextRunUtc" ON "EmailSchedules" ("Id");
            """;

        var commands = RelationalSchemaUpgradePlanner.SelectMissingTableCommands(script, ["EmailSchedules"]);

        commands.Should().BeEmpty();
    }

    [Fact]
    public void SelectMissingTableCommands_HandlesSchemaQualifiedIdentifiers()
    {
        const string script = """
            CREATE TABLE [dbo].[EmailDeliveryAttempts] (
                [Id] uniqueidentifier NOT NULL,
                CONSTRAINT [PK_EmailDeliveryAttempts] PRIMARY KEY ([Id])
            );
            CREATE INDEX [IX_EmailDeliveryAttempts_Message] ON [dbo].[EmailDeliveryAttempts] ([Id]);
            """;

        var commands = RelationalSchemaUpgradePlanner.SelectMissingTableCommands(script, Array.Empty<string>());

        commands.Should().HaveCount(2);
        commands[0].Should().Contain("[dbo].[EmailDeliveryAttempts]");
        commands[1].Should().Contain("ON [dbo].[EmailDeliveryAttempts]");
    }
}
