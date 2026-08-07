using Microsoft.AspNetCore.Components;

namespace AIWordPressManager.Web.Components.Pages;

public partial class GlobalUsersWorkspace
{
    private bool _workspaceQueryInitialized;
    private Guid _pendingWorkspaceSiteId;

    [Parameter]
    [SupplyParameterFromQuery(Name = "siteId")]
    public Guid? WorkspaceSiteId { get; set; }

    protected override async Task OnParametersSetAsync()
    {
        _pendingWorkspaceSiteId = WorkspaceSiteId ?? Guid.Empty;

        if (_loadingSites)
            return;

        await ApplyWorkspaceQueryAsync();
    }

    protected override async Task OnAfterRenderAsync(bool firstRender)
    {
        await base.OnAfterRenderAsync(firstRender);

        if (_loadingSites || _workspaceQueryInitialized)
            return;

        await ApplyWorkspaceQueryAsync();
        await InvokeAsync(StateHasChanged);
    }

    private async Task ApplyWorkspaceQueryAsync()
    {
        var nextSiteId = _pendingWorkspaceSiteId != Guid.Empty &&
                         _sites.Any(site => site.Id == _pendingWorkspaceSiteId)
            ? _pendingWorkspaceSiteId
            : Guid.Empty;

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
