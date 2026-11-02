# AIMW Connector v0.2.1 — WordPress owner pairing

Installing the plugin does **not** automatically connect a WordPress site to Laravel AIWMWeb.

1. Sign in to Laravel AIWMWeb and open **Sites > Add site** inside the correct tenant.
2. In WordPress, open **Users > Profile > Application Passwords**, create a dedicated application password and enter it with the WordPress username in Laravel. Save/test the HTTPS WordPress REST connection.
3. After that site is saved, use Laravel **Generate one-time pairing token** for that specific site. It expires in 10 minutes.
4. In WordPress as an administrator open **Tools > AIMW Connector**. Supply the Laravel platform HTTPS URL and the one-time token, then press **Pair with Laravel platform**.
5. Verify the Connector state changes to **CONNECTED**, check the enabled scopes in WordPress and confirm connectivity/verification in Laravel. Sensitive scopes are disabled until the WordPress owner explicitly enables them.

The Connector pairing call requires a WordPress admin capability, WordPress admin nonce, a publicly resolvable HTTPS Laravel URL and a current Laravel token bound to the intended tenant/site. Avoid posting token values in tickets, screenshots, chat or logs. A failed pair does not imply success and must not modify local credentials. The plugin uses HMAC-SHA256, versioned signed requests, nonce/replay prevention and audited scoped operations. It exposes no arbitrary PHP, SQL or filesystem command execution.

For cPanel staging, initial REST connection works without running a permanent queue worker; follow the cPanel runbook before enabling bounded cron for queued syncs. Do not enable remote AI/Ollama just to pair a Connector.
