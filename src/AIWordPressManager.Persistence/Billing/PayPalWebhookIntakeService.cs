using AIWordPressManager.Application.Abstractions.Billing;

namespace AIWordPressManager.Persistence.Billing;

public sealed class PayPalWebhookIntakeService(
    IPaymentGatewayRegistry paymentGatewayRegistry,
    IPayPalWebhookInbox webhookInbox) : IPayPalWebhookIntakeService
{
    public async Task<PayPalWebhookIntakeResult> HandleAsync(
        string body,
        IReadOnlyDictionary<string, string> headers,
        DateTime receivedAtUtc,
        CancellationToken cancellationToken = default)
    {
        if (receivedAtUtc.Kind != DateTimeKind.Utc)
            throw new ArgumentException("Webhook receive timestamp must be UTC.", nameof(receivedAtUtc));

        GatewayWebhookEnvelope envelope;
        try
        {
            envelope = new GatewayWebhookEnvelope(body, headers, $"paypal-webhook:{Guid.NewGuid():N}");
        }
        catch (ArgumentException)
        {
            return new(PayPalWebhookIntakeStatus.Rejected, "PayPal webhook request exceeded or violated the supported request boundary.");
        }

        GatewayWebhookVerificationResult verification;
        try
        {
            var gateway = paymentGatewayRegistry.GetRequired("paypal", PaymentGatewayCapability.WebhookVerification);
            verification = await gateway.VerifyWebhookAsync(envelope, cancellationToken);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception)
        {
            return new(PayPalWebhookIntakeStatus.Unavailable, "PayPal webhook verification is temporarily unavailable.");
        }

        if (!verification.IsAuthentic || verification.Event is null)
        {
            return new(
                PayPalWebhookIntakeStatus.Rejected,
                string.IsNullOrWhiteSpace(verification.SanitizedFailure)
                    ? "PayPal webhook verification failed."
                    : verification.SanitizedFailure);
        }

        try
        {
            var accepted = await webhookInbox.AcceptVerifiedAsync(
                verification.Event,
                receivedAtUtc,
                cancellationToken);
            return new(
                accepted.Inserted ? PayPalWebhookIntakeStatus.Accepted : PayPalWebhookIntakeStatus.Duplicate,
                accepted.Inserted
                    ? "Verified PayPal webhook accepted."
                    : "Verified PayPal webhook was already accepted.",
                accepted.Event.ProviderEventId);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (InvalidOperationException)
        {
            return new(
                PayPalWebhookIntakeStatus.Rejected,
                "Verified PayPal webhook conflicted with previously accepted event data.",
                verification.Event.ProviderEventId);
        }
        catch (Exception)
        {
            return new(
                PayPalWebhookIntakeStatus.Unavailable,
                "Verified PayPal webhook could not be durably accepted.",
                verification.Event.ProviderEventId);
        }
    }
}
