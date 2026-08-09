using AIWordPressManager.Application.Abstractions.AI;

namespace AIWordPressManager.Web.Services;

public sealed class AIPromptTemplateAdminService(
    IAIPromptTemplateStore store,
    CurrentUserContext currentUser)
{
    public IReadOnlyList<AIPromptTemplateDefinition> GetAll()
    {
        RequireAdministrator();
        return store.GetDefinitions();
    }

    public AIPromptTemplateDefinition? Get(string key)
    {
        RequireAdministrator();
        return store.GetDefinition(key);
    }

    public IReadOnlyList<AIPromptTemplateVersion> GetHistory(string key)
    {
        RequireAdministrator();
        return store.GetHistory(key);
    }

    public AIPromptTemplateDefinition Save(AIPromptTemplateInput input)
    {
        var actor = RequireAdministrator();
        return store.Save(input, actor);
    }

    public AIPromptTemplateDefinition SetEnabled(string key, bool enabled)
    {
        var actor = RequireAdministrator();
        return store.SetEnabled(key, enabled, actor);
    }

    public AIPromptTemplateDefinition Restore(string key, int revision)
    {
        var actor = RequireAdministrator();
        return store.Restore(key, revision, actor);
    }

    private string RequireAdministrator()
    {
        var userId = currentUser.RequireAdministrator();
        return string.IsNullOrWhiteSpace(currentUser.UserName) ? userId.ToString() : currentUser.UserName;
    }
}
