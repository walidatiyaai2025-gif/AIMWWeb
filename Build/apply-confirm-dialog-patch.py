from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
sites_path = root / "src/AIWordPressManager.Web/Components/Pages/Sites.razor"
project_path = root / "src/AIWordPressManager.Web/AIWordPressManager.Web.csproj"

text = sites_path.read_text(encoding="utf-8")

text = text.replace("@inject IJSRuntime JS\n", "")
text = text.replace(
    '<AppButton Icon="⌫" Variant="danger" Disabled="@busy" OnClick="args => DeleteAsync(site.Id, site.Name)" />',
    '<AppButton Icon="⌫" Variant="danger" Disabled="@busy" OnClick="args => RequestDelete(site.Id, site.Name)" />'
)

dialog_markup = '''\n\n    <AppConfirmDialog Open="@_deleteDialogOpen"\n                      OpenChanged="DeleteDialogOpenChangedAsync"\n                      Eyebrow="@(L.IsArabic ? "تأكيد الحذف" : "DELETE CONFIRMATION")"\n                      Title="@(L.IsArabic ? "حذف الموقع" : "Delete site")"\n                      Message="@(L.IsArabic ? $"هل تريد حذف الموقع «{_deleteSiteName}»؟" : $"Delete site ‘{_deleteSiteName}’?")"\n                      Details="@(L.IsArabic ? "سيتم حذف بيانات الاتصال المحلية لهذا الموقع. لا يمكن التراجع عن هذه العملية." : "The local connection profile for this site will be removed. This action cannot be undone.")"\n                      ConfirmText="@(L.IsArabic ? "حذف الموقع" : "Delete site")"\n                      CancelText="@L["Cancel"]"\n                      ConfirmVariant="danger"\n                      ConfirmIcon="⌫"\n                      Busy="@_deleting"\n                      OnConfirm="ConfirmDeleteAsync"\n                      OnCancel="CancelDeleteAsync" />'''

anchor = "\n</div>\n\n@code {"
if dialog_markup.strip() not in text:
    if anchor not in text:
        raise RuntimeError("Could not find Sites markup/code anchor.")
    text = text.replace(anchor, dialog_markup + anchor, 1)

text = text.replace(
    "private Guid? _editingId, _busySiteId;",
    "private Guid? _editingId, _busySiteId, _deleteSiteId;\n    private bool _deleteDialogOpen, _deleting;\n    private string _deleteSiteName = string.Empty;"
)

pattern = re.compile(
    r"\n    private async Task DeleteAsync\(Guid id, string name\)\n    \{.*?\n    \}\n\n    private void Warn",
    re.S,
)
replacement = '''\n    private Task RequestDelete(Guid id, string name)\n    {\n        _deleteSiteId = id;\n        _deleteSiteName = name;\n        _deleteDialogOpen = true;\n        return Task.CompletedTask;\n    }\n\n    private Task DeleteDialogOpenChangedAsync(bool value)\n    {\n        if (_deleting) return Task.CompletedTask;\n        _deleteDialogOpen = value;\n        if (!value) ClearDeleteRequest();\n        return Task.CompletedTask;\n    }\n\n    private Task CancelDeleteAsync()\n    {\n        if (!_deleting) ClearDeleteRequest();\n        return Task.CompletedTask;\n    }\n\n    private async Task ConfirmDeleteAsync()\n    {\n        if (_deleteSiteId is not { } id || _deleting) return;\n\n        try\n        {\n            _deleting = true;\n            _busySiteId = id;\n            await SiteService.DeleteSiteAsync(id);\n            _sites.RemoveAll(x => x.Id == id);\n            ApplyFilters();\n            Notifications.Success(L["DeletedSuccess"], L["Sites"]);\n            _deleteDialogOpen = false;\n            ClearDeleteRequest();\n        }\n        catch (Exception ex)\n        {\n            Fail(L.TranslateMessage(ex.Message), ex.ToString());\n        }\n        finally\n        {\n            _deleting = false;\n            _busySiteId = null;\n        }\n    }\n\n    private void ClearDeleteRequest()\n    {\n        _deleteSiteId = null;\n        _deleteSiteName = string.Empty;\n    }\n\n    private void Warn'''

text, count = pattern.subn(replacement, text, count=1)
if count != 1:
    raise RuntimeError("Could not replace the legacy DeleteAsync implementation.")

sites_path.write_text(text, encoding="utf-8")

project = project_path.read_text(encoding="utf-8")
project = re.sub(r"<Version>[^<]+</Version>", "<Version>155.39.0</Version>", project)
project = re.sub(r"<AssemblyVersion>[^<]+</AssemblyVersion>", "<AssemblyVersion>155.39.0.0</AssemblyVersion>", project)
project = re.sub(r"<FileVersion>[^<]+</FileVersion>", "<FileVersion>155.39.0.0</FileVersion>", project)
project = re.sub(r"<InformationalVersion>[^<]+</InformationalVersion>", "<InformationalVersion>155.39.0</InformationalVersion>", project)
project_path.write_text(project, encoding="utf-8")

print("Applied AppConfirmDialog integration and bumped version to 155.39.0.")
