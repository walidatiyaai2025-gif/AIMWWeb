namespace AIWordPressManager.Web.Services;

public static class ExecutionCenterExtensions
{
    public static ExecutionJob Enqueue(
        this ExecutionCenterService service,
        string title,
        string type,
        string siteName,
        int totalItems,
        string idempotencyKey,
        string correlationId)
    {
        ArgumentNullException.ThrowIfNull(service);
        _ = idempotencyKey;
        _ = correlationId;
        return service.Enqueue(title, type, siteName, totalItems);
    }
}
