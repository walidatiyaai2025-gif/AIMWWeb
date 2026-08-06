using Microsoft.AspNetCore.Components;

namespace AIWordPressManager.Web.Components.Pages;

public partial class GlobalUsersWorkspace
{
    private bool _workspaceQueryInitialized;

    [Parameter]
    [SupplyParameterFromQuery(Name = "siteId")]
    public Guid? WorkspaceSiteId { get; set; }

    protected override async Task OnParametersSetAsync()
    {
        var nextSiteId = WorkspaceSiteId ?? Guid.Empty;
        var changed = _selectedSiteId != nextSiteId;
        _selectedSiteId = nextSiteId;

        if (!_workspaceQueryInitialized)
        {
            _workspaceQueryInitialized = true;
            if (_selectedSiteId != Guid.Empty)
                await ResetAndLoadWorkspaceAsync();
            return;
        }

        if (changed)
            await ResetAndLoadWorkspaceAsync();
    }

    private async Task ResetAndLoadWorkspaceAsync()
    {
        _page = 1;
        _search = string.Empty;
        _role = "all";
        _message = null;
        _isError = false;
        _result = new(
            true,
            string.Empty,
            Array.Empty<AIWordPressManager.Web.Services.WordPressUserView>(),
            0,
            1);

        if (_selectedSiteId != Guid.Empty)
            await LoadAsync();
    }
}
