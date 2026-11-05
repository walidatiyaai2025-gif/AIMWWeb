# AIMW Connector v0.2.2

Install the `aimw-connector` directory as a WordPress plugin. An administrator pairs it by POSTing `platform_url` and the one-time `pairing_token` to `/wp-json/aimw/v1/pair` while authenticated to WordPress.

The connector accepts only versioned semantic REST operations. It uses HMAC-SHA256, timestamp/nonce replay protection, request/correlation/operation IDs, explicit scopes, WordPress capability checks for pairing, WordPress APIs for reads/writes, idempotent execution receipts, and a bounded local audit. It provides no raw SQL, arbitrary PHP, filesystem, or command execution surface.


## Connect from the WordPress dashboard

1. Install the released `aimw-connector.zip` from WordPress → Plugins → Add New → Upload Plugin and activate it.
2. Open WordPress → Tools → AIMW Connector.
3. Enter the AIWMWeb Manager HTTPS URL (for example `https://aiwm.walidatiya.com`), and a one-time pairing token generated in the corresponding AIWMWeb WordPress site workflow.
4. Click **Connect & Verify**. A successful pairing stores the server-issued connector identity and secret locally, never the one-time token. Configure the permitted scopes after pairing.
5. If pairing fails, check HTTPS, host reachability and that the token is valid for the correct site. No secret should be pasted into tickets or logs.

The pairing handler is administrator-only and CSRF-protected; outbound calls use WordPress safe HTTP validation to avoid requests to internal/private targets.

## v0.2.2 — WordPress signed-header normalization fix

WordPress canonicalizes REST header names by converting hyphens to underscores. Earlier connector builds read `get_headers()` using hyphenated keys, incorrectly rejecting correctly signed manager requests as `401 invalid_protocol`. This release uses `WP_REST_Request::get_header()` for all required `X-AIMW-*` fields; HMAC signatures, nonce replay, timestamps, tenant/site binding and scope restrictions are still enforced.

**Deployment:** Install the updated AIMW Connector ZIP in **WordPress**, not just the Laravel dashboard update ZIP. In WordPress Admin → Plugins → Add New Plugin → Upload Plugin, select `AIMW-Connector-0.2.2-<SHA>.zip` from the green runtime-artifacts workflow. If prompted to replace the existing plugin, choose replacement; do not uninstall/delete the plugin or clear its `aimw_connector` option. Back up WordPress before replacement.

**Verification:** In Laravel AIWMWeb Site Details, test the connector connection and retry sync. A valid existing pairing should now accept signed health/content requests. If a binding mismatch persists after upgrading, compare only the nonsecret protocol version, tenant/site IDs and connector UUID, then perform a fresh one-time-token pairing as needed; never disable signature verification.
