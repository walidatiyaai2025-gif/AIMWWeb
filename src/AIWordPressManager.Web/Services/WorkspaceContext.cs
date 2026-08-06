namespace AIWordPressManager.Web.Services;

public sealed class WorkspaceContext
{
    public Guid? ActiveSiteId { get; private set; }
    public string? ActiveSiteName { get; private set; }

    public event Action? Changed;

    public void SetActiveSite(Guid? siteId, string? siteName)
    {
        if (ActiveSiteId == siteId && string.Equals(ActiveSiteName, siteName, StringComparison.Ordinal))
            return;

        ActiveSiteId = siteId;
        ActiveSiteName = siteName;
        Changed?.Invoke();
    }

    public void Clear() => SetActiveSite(null, null);
}
