using System.Diagnostics;
using System.Net;
using System.Net.Mail;
using System.Net.Sockets;

namespace AIWordPressManager.Web.Services;

public sealed class SmtpDiagnosticsService
{
    public async Task<SmtpDiagnosticResult> DiagnoseAsync(
        SiteMailDeliveryProfile profile,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(profile);
        ValidateProfile(profile);

        var steps = new List<SmtpDiagnosticStep>();
        var total = Stopwatch.StartNew();

        try
        {
            var dnsWatch = Stopwatch.StartNew();
            var addresses = await Dns.GetHostAddressesAsync(profile.Host, cancellationToken);
            dnsWatch.Stop();
            if (addresses.Length == 0)
                return Fail("DNS", "SMTP host did not resolve to an IP address.", steps, total);

            steps.Add(new SmtpDiagnosticStep(
                "DNS",
                true,
                $"Resolved {addresses.Length} address(es) in {dnsWatch.ElapsedMilliseconds} ms."));
        }
        catch (Exception ex) when (ex is SocketException or ArgumentException)
        {
            return Fail("DNS", "SMTP host could not be resolved. Check the host name and DNS connectivity.", steps, total);
        }

        try
        {
            var tcpWatch = Stopwatch.StartNew();
            using var client = new TcpClient();
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
            timeout.CancelAfter(TimeSpan.FromSeconds(10));
            await client.ConnectAsync(profile.Host, profile.Port, timeout.Token);
            tcpWatch.Stop();
            steps.Add(new SmtpDiagnosticStep(
                "TCP",
                true,
                $"Connected to SMTP port {profile.Port} in {tcpWatch.ElapsedMilliseconds} ms."));
        }
        catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
        {
            return Fail("TCP", $"Connection to SMTP port {profile.Port} timed out.", steps, total);
        }
        catch (SocketException)
        {
            return Fail("TCP", $"SMTP port {profile.Port} could not be reached. Check firewall, port, and server availability.", steps, total);
        }

        total.Stop();
        return new SmtpDiagnosticResult(
            true,
            "Connection prerequisites passed. Use Send test email to verify TLS and authentication.",
            steps,
            total.ElapsedMilliseconds);
    }

    public async Task<SmtpDiagnosticResult> SendTestAsync(
        SiteMailDeliveryProfile profile,
        string recipient,
        string? subject = null,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(profile);
        ValidateProfile(profile);
        ValidateEmail(recipient, "Test recipient");

        var steps = new List<SmtpDiagnosticStep>();
        var total = Stopwatch.StartNew();
        var preflight = await DiagnoseAsync(profile, cancellationToken);
        steps.AddRange(preflight.Steps);
        if (!preflight.IsSuccess)
            return new SmtpDiagnosticResult(false, preflight.Message, steps, preflight.DurationMilliseconds);

        try
        {
            using var message = new MailMessage
            {
                From = string.IsNullOrWhiteSpace(profile.FromName)
                    ? new MailAddress(profile.FromAddress)
                    : new MailAddress(profile.FromAddress, profile.FromName),
                Subject = string.IsNullOrWhiteSpace(subject) ? "AI WordPress Manager - SMTP test" : subject,
                Body = "This is a test email from AI WordPress Manager. Your saved SMTP configuration completed DNS, network, TLS/authentication, and message submission successfully.",
                IsBodyHtml = false
            };
            message.To.Add(new MailAddress(recipient.Trim()));
            if (!string.IsNullOrWhiteSpace(profile.ReplyToAddress))
                message.ReplyToList.Add(new MailAddress(profile.ReplyToAddress));

#pragma warning disable SYSLIB0014
            using var smtp = new SmtpClient(profile.Host, profile.Port)
            {
                EnableSsl = profile.EnableSsl,
                DeliveryMethod = SmtpDeliveryMethod.Network,
                UseDefaultCredentials = false,
                Timeout = 15000
            };
#pragma warning restore SYSLIB0014

            if (!string.IsNullOrWhiteSpace(profile.UserName))
                smtp.Credentials = new NetworkCredential(profile.UserName, profile.Password ?? string.Empty);

            var sendWatch = Stopwatch.StartNew();
            await smtp.SendMailAsync(message).WaitAsync(TimeSpan.FromSeconds(20), cancellationToken);
            sendWatch.Stop();
            steps.Add(new SmtpDiagnosticStep(
                "SMTP",
                true,
                $"Test message was accepted by the SMTP server in {sendWatch.ElapsedMilliseconds} ms."));

            total.Stop();
            return new SmtpDiagnosticResult(
                true,
                "Test email sent successfully. SMTP connectivity, TLS/authentication, and message submission passed.",
                steps,
                total.ElapsedMilliseconds);
        }
        catch (TimeoutException)
        {
            return Fail("SMTP", "SMTP test timed out during TLS, authentication, or message submission.", steps, total);
        }
        catch (SmtpException ex)
        {
            var message = ex.StatusCode switch
            {
                SmtpStatusCode.ClientNotPermitted or SmtpStatusCode.MustIssueStartTlsFirst =>
                    "SMTP server rejected the connection policy. Check TLS/SSL requirements and account permissions.",
                SmtpStatusCode.MailboxUnavailable or SmtpStatusCode.MailboxBusy =>
                    "SMTP server could not deliver to the test recipient. Check the recipient address and mailbox state.",
                _ => "SMTP server rejected the test message. Check authentication, TLS/SSL, sender permissions, and server policy."
            };
            return Fail("SMTP", message, steps, total);
        }
        catch (AuthenticationException)
        {
            return Fail("TLS", "TLS authentication failed. Check the server certificate, TLS requirements, and system trust store.", steps, total);
        }
        catch (Exception ex) when (ex is IOException or SocketException or InvalidOperationException)
        {
            return Fail("SMTP", "SMTP test could not complete because the connection was interrupted or the saved profile is incomplete.", steps, total);
        }
    }

    private static SmtpDiagnosticResult Fail(
        string name,
        string message,
        List<SmtpDiagnosticStep> steps,
        Stopwatch total)
    {
        total.Stop();
        steps.Add(new SmtpDiagnosticStep(name, false, message));
        return new SmtpDiagnosticResult(false, message, steps, total.ElapsedMilliseconds);
    }

    private static void ValidateProfile(SiteMailDeliveryProfile profile)
    {
        if (string.IsNullOrWhiteSpace(profile.Host))
            throw new InvalidOperationException("SMTP host is not configured.");
        if (profile.Port is < 1 or > 65535)
            throw new InvalidOperationException("SMTP port is invalid.");
        ValidateEmail(profile.FromAddress, "From address");
        if (!string.IsNullOrWhiteSpace(profile.ReplyToAddress))
            ValidateEmail(profile.ReplyToAddress, "Reply-to address");
    }

    private static void ValidateEmail(string? value, string label)
    {
        if (string.IsNullOrWhiteSpace(value))
            throw new InvalidOperationException($"{label} is required.");
        try
        {
            var parsed = new MailAddress(value.Trim());
            if (!string.Equals(parsed.Address, value.Trim(), StringComparison.OrdinalIgnoreCase))
                throw new FormatException();
        }
        catch (FormatException)
        {
            throw new InvalidOperationException($"{label} is not a valid email address.");
        }
    }
}

public sealed record SmtpDiagnosticStep(string Name, bool IsSuccess, string Message);
public sealed record SmtpDiagnosticResult(bool IsSuccess, string Message, IReadOnlyList<SmtpDiagnosticStep> Steps, long DurationMilliseconds);
