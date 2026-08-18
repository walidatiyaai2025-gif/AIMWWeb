using System.Text;
using AIWordPressManager.Application.Abstractions.Billing;

namespace AIWordPressManager.Web.Services;

public static class PayPalWebhookApi
{
    private const int MaximumBodyCharacters = 1_000_000;

    public static IEndpointRouteBuilder MapPayPalWebhookApi(this IEndpointRouteBuilder endpoints)
    {
        endpoints.MapPost("/api/billing/paypal/webhook", HandleAsync)
            .AllowAnonymous()
            .DisableAntiforgery();
        return endpoints;
    }

    private static async Task<IResult> HandleAsync(
        HttpRequest request,
        IPayPalWebhookIntakeService intake,
        CancellationToken cancellationToken)
    {
        if (request.ContentLength is > MaximumBodyCharacters)
            return Results.StatusCode(StatusCodes.Status413PayloadTooLarge);

        string body;
        try
        {
            body = await ReadBoundedBodyAsync(request, cancellationToken);
        }
        catch (InvalidDataException)
        {
            return Results.StatusCode(StatusCodes.Status413PayloadTooLarge);
        }

        var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var headerName in new[]
                 {
                     "PAYPAL-AUTH-ALGO",
                     "PAYPAL-CERT-URL",
                     "PAYPAL-TRANSMISSION-ID",
                     "PAYPAL-TRANSMISSION-SIG",
                     "PAYPAL-TRANSMISSION-TIME"
                 })
        {
            if (request.Headers.TryGetValue(headerName, out var values))
                headers[headerName] = values.ToString();
        }

        var result = await intake.HandleAsync(body, headers, DateTime.UtcNow, cancellationToken);
        return result.Status switch
        {
            PayPalWebhookIntakeStatus.Accepted => Results.Ok(new
            {
                accepted = true,
                duplicate = false,
                eventId = result.ProviderEventId
            }),
            PayPalWebhookIntakeStatus.Duplicate => Results.Ok(new
            {
                accepted = true,
                duplicate = true,
                eventId = result.ProviderEventId
            }),
            PayPalWebhookIntakeStatus.Rejected => Results.BadRequest(new
            {
                accepted = false,
                error = result.SanitizedSummary
            }),
            _ => Results.Json(
                new { accepted = false, error = result.SanitizedSummary },
                statusCode: StatusCodes.Status503ServiceUnavailable)
        };
    }

    private static async Task<string> ReadBoundedBodyAsync(
        HttpRequest request,
        CancellationToken cancellationToken)
    {
        using var reader = new StreamReader(
            request.Body,
            Encoding.UTF8,
            detectEncodingFromByteOrderMarks: false,
            bufferSize: 8192,
            leaveOpen: true);
        var buffer = new char[8192];
        var builder = new StringBuilder();
        while (true)
        {
            var read = await reader.ReadAsync(buffer.AsMemory(), cancellationToken);
            if (read == 0) break;
            if (builder.Length + read > MaximumBodyCharacters)
                throw new InvalidDataException("Webhook body exceeded the supported boundary.");
            builder.Append(buffer, 0, read);
        }
        return builder.ToString();
    }
}
