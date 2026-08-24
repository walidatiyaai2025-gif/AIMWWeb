using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class NotificationInboxTruthfulnessContractTests
{
    [Fact]
    public void Notification_page_never_reports_mark_all_success_after_reload_failure()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/NotificationInbox.razor");

        page.Should().Contain("if (Reload(notifyFailure: false))");
        page.Should().Contain("All notifications marked as read.");
        page.Should().Contain("Saved; refresh required");
        page.Should().Contain("Use Refresh to reconcile the saved state.");

        var reloadCheck = page.IndexOf("if (Reload(notifyFailure: false))", StringComparison.Ordinal);
        var success = page.IndexOf("All notifications marked as read.", StringComparison.Ordinal);
        success.Should().BeGreaterThan(reloadCheck);
    }

    [Fact]
    public void Per_item_mutations_honor_service_no_op_results_and_reconciliation_failure()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/NotificationInbox.razor");

        page.Should().Contain("if (!Inbox.MarkRead(CurrentUser.UserId, item.Id))");
        page.Should().Contain("if (!Inbox.Dismiss(CurrentUser.UserId, item.Id))");
        page.Should().Contain("if (!Reload(notifyFailure: false))");
        page.Should().Contain("No change was made");
        page.Should().Contain("The notification was marked as read, but the inbox could not be refreshed.");
        page.Should().Contain("The notification was dismissed, but the inbox could not be refreshed.");
    }

    [Fact]
    public void Reload_failure_is_bounded_and_does_not_expose_exception_details()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/NotificationInbox.razor");

        page.Should().Contain("private bool Reload(bool notifyFailure = true)");
        page.Should().Contain("catch (Exception)");
        page.Should().NotContain("L.TranslateMessage(ex.Message)");
        page.Should().NotContain("ex.ToString()");
        page.Should().Contain("The notification inbox could not be refreshed. Try again.");
    }

    [Fact]
    public void Notification_store_remains_owner_scoped_for_read_and_mutations()
    {
        var root = Path.Combine(Path.GetTempPath(), "aimw-notification-contract", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(root);
        var databasePath = Path.Combine(root, "notifications.db");

        try
        {
            var inbox = NotificationInboxService.ForDatabase(databasePath);
            var ownerA = Guid.NewGuid();
            var ownerB = Guid.NewGuid();
            var a = inbox.Create(ownerA, "A", "Owned by A", NotificationSeverity.Information);
            var b = inbox.Create(ownerB, "B", "Owned by B", NotificationSeverity.Warning);

            inbox.Get(ownerA).Select(x => x.Id).Should().Equal(a.Id);
            inbox.Get(ownerB).Select(x => x.Id).Should().Equal(b.Id);

            inbox.MarkRead(ownerB, a.Id).Should().BeFalse();
            inbox.Dismiss(ownerB, a.Id).Should().BeFalse();
            inbox.MarkAllRead(ownerB).Should().Be(1);

            inbox.Get(ownerA).Single().IsRead.Should().BeFalse();
            inbox.Get(ownerB).Single().IsRead.Should().BeTrue();
        }
        finally
        {
            try { Directory.Delete(root, recursive: true); } catch { }
        }
    }

    [Fact]
    public void Unsupported_mutation_controls_remain_absent()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/NotificationInbox.razor");

        page.Should().NotContain("MarkUnread");
        page.Should().NotContain("HardDelete");
        page.Should().NotContain("ClearAll");
        page.Should().Contain("@onclick=\"Reload\"");
        page.Should().Contain("@onclick=\"MarkAllRead\"");
        page.Should().Contain("MarkRead(item)");
        page.Should().Contain("Dismiss(item)");
    }

    private static string ReadRepositoryFile(string relativePath)
    {
        var directory = new DirectoryInfo(Directory.GetCurrentDirectory());
        while (directory is not null)
        {
            var candidate = Path.Combine(directory.FullName, relativePath.Replace('/', Path.DirectorySeparatorChar));
            if (File.Exists(candidate))
                return File.ReadAllText(candidate);

            directory = directory.Parent;
        }

        throw new FileNotFoundException($"Could not locate repository file '{relativePath}' from '{Directory.GetCurrentDirectory()}'.");
    }
}
