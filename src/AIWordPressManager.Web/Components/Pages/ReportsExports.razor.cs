namespace AIWordPressManager.Web.Components.Pages;

public partial class ReportsExports : IDisposable
{
    void IDisposable.Dispose()
    {
        L.Changed -= Changed;
    }
}
