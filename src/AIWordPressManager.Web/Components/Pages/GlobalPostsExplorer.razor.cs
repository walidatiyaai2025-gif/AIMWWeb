using Microsoft.AspNetCore.Components;

namespace AIWordPressManager.Web.Components.Pages;

public partial class GlobalPostsExplorer
{
    private bool _workspaceQueryInitialized;

    [Parameter]
    [SupplyParameterFromQuery(Name = "siteId")]
    public Guid? WorkspaceSiteId { get; set; }

    protected override async Task OnParametersSetAsync()
    {
        var nextFilter = WorkspaceSiteId?.ToString() ?? string.Empty;
        var changed = !string.Equals(_siteFilter, nextFilter, StringComparison.OrdinalIgnoreCase);
        _siteFilter = nextFilter;

        if (!_workspaceQueryInitialized)
        {
            _workspaceQueryInitialized = true;
            return;
        }

        if (changed)
        {
            _page = 1;
            await LoadAsync();
        }
    }
}
