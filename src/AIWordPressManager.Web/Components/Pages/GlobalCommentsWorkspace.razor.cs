using Microsoft.AspNetCore.Components;

namespace AIWordPressManager.Web.Components.Pages;

public partial class GlobalCommentsWorkspace
{
    private bool _workspaceQueryInitialized;

    [Parameter]
    [SupplyParameterFromQuery(Name = "siteId")]
    public Guid? WorkspaceSiteId { get; set; }

    protected override async Task OnParametersSetAsync()
    {
        var nextValue = WorkspaceSiteId?.ToString() ?? string.Empty;
        var changed = !string.Equals(_selectedSiteId, nextValue, StringComparison.OrdinalIgnoreCase);
        _selectedSiteId = nextValue;

        if (!_workspaceQueryInitialized)
        {
            _workspaceQueryInitialized = true;
            if (WorkspaceSiteId.HasValue)
                await ResetAndLoadWorkspaceAsync();
            return;
        }

        if (changed)
            await ResetAndLoadWorkspaceAsync();
    }

    private async Task ResetAndLoadWorkspaceAsync()
    {
        _page = 1;
        _status = "all";
        _search = string.Empty;
        _message = null;
        _isError = false;
        _result = new(true, string.Empty, [], 0, 1);

        if (SelectedSiteId.HasValue)
            await LoadAsync();
    }
}
