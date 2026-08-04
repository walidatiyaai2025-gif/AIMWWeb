namespace AIWordPressManager.Application.Abstractions;

public interface IApplicationPathService
{
    string GetApplicationDataDirectory();

    string GetDatabasePath();

    string GetLogsDirectory();

    string GetScreenshotsDirectory();

    string GetBackupsDirectory();

    string GetExportsDirectory();

    string GetTemporaryDirectory();
}
