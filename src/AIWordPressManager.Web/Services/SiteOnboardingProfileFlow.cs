namespace AIWordPressManager.Web.Services;

public static class SiteOnboardingProfileFlow
{
    public static async Task<Guid> PersistAsync(
        Guid? existingSiteId,
        Func<Task<Guid>> createAsync,
        Func<Guid, Task> updateAsync)
    {
        ArgumentNullException.ThrowIfNull(createAsync);
        ArgumentNullException.ThrowIfNull(updateAsync);

        if (existingSiteId is { } siteId)
        {
            await updateAsync(siteId);
            return siteId;
        }

        return await createAsync();
    }
}
