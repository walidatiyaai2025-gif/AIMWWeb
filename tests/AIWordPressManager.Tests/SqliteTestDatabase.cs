using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Tests;

internal static class SqliteTestDatabase
{
    public static void Create(string path)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(Path.GetFullPath(path))!);
        if (File.Exists(path)) File.Delete(path);

        var builder = new SqliteConnectionStringBuilder
        {
            DataSource = Path.GetFullPath(path),
            Mode = SqliteOpenMode.ReadWriteCreate,
            Pooling = false
        };
        using var connection = new SqliteConnection(builder.ToString());
        connection.Open();
        using var command = connection.CreateCommand();
        command.CommandText = "CREATE TABLE IF NOT EXISTS restore_fixture (id INTEGER PRIMARY KEY, value TEXT NOT NULL); INSERT INTO restore_fixture(value) VALUES ('verified');";
        command.ExecuteNonQuery();
    }
}
