# AIMW Connector v0.2.3

Install the `aimw-connector` directory as a WordPress plugin. An administrator pairs it by POSTing `platform_url` and the one-time `pairing_token` to `/wp-json/aimw/v1/pair` while authenticated to WordPress.

The connector accepts only versioned semantic REST operations. It uses HMAC-SHA256, timestamp/nonce replay protection, request/correlation/operation IDs, explicit scopes, WordPress capability checks for pairing, WordPress APIs for reads/writes, idempotent execution receipts, and a bounded local audit. It provides no raw SQL, arbitrary PHP, filesystem, or command execution surface.


## Connect from the WordPress dashboard

1. Install the released `aimw-connector.zip` from WordPress → Plugins → Add New → Upload Plugin and activate it.
2. Open WordPress → Tools → AIMW Connector.
3. Enter the AIWMWeb Manager HTTPS URL (for example `https://aiwm.walidatiya.com`), and a one-time pairing token generated in the corresponding AIWMWeb WordPress site workflow.
4. Click **Connect & Verify**. A successful pairing stores the server-issued connector identity and secret locally, never the one-time token. Configure the permitted scopes after pairing.
5. If pairing fails, check HTTPS, host reachability and that the token is valid for the correct site. No secret should be pasted into tickets or logs.

The pairing handler is administrator-only and CSRF-protected; outbound calls use WordPress safe HTTP validation to avoid requests to internal/private targets.

## v0.2.3 — Authoritative signed content resource reads

For sites paired with AIMW Connector but without a native WordPress Application Password, the Laravel canonical content synchronizer now uses signed `GET /wp-json/aimw/v1/content/resources/{resource}` with `page`, `per_page` (1–100), and bounded `modified_after` support. A matching `/{id}` GET provides read-after-write and conflict verification. Supported resources: posts, pages, media, categories, tags and comments. Results come from real WordPress records, not fabricated success or an unsupported `content.list` mutation envelope. Every call requires `content.read`, HMAC verification, tenant/site/connector binding and nonce replay protection.

**Upgrade:** Install the v0.2.3 Connector ZIP via WordPress Plugins → Add New → Upload Plugin → Replace Existing. Preserve the existing pairing and back up WordPress first. Update Laravel to the signed release built from the same SHA, then open Content Hub → **Sync WordPress content now**. The hub reports real Laravel post/page counts and shows errors when content cannot be read. Do not infer synchronized data from green ENABLED navigation badges. An older plugin lacking the signed resource endpoint will return an explicit error instead of an invented zero-result sync.

## v0.2.2 — WordPress signed-header normalization fix

WordPress canonicalizes REST header names by converting hyphens to underscores. Earlier connector builds read `get_headers()` using hyphenated keys, incorrectly rejecting correctly signed manager requests as `401 invalid_protocol`. This release uses `WP_REST_Request::get_header()` for all required `X-AIMW-*` fields; HMAC signatures, nonce replay, timestamps, tenant/site binding and scope restrictions are still enforced.

**Deployment:** Install the updated AIMW Connector ZIP in **WordPress**, not just the Laravel dashboard update ZIP. In WordPress Admin → Plugins → Add New Plugin → Upload Plugin, select `AIMW-Connector-0.2.2-<SHA>.zip` from the green runtime-artifacts workflow. If prompted to replace the existing plugin, choose replacement; do not uninstall/delete the plugin or clear its `aimw_connector` option. Back up WordPress before replacement.

**Verification:** In Laravel AIWMWeb Site Details, test the connector connection and retry sync. A valid existing pairing should now accept signed health/content requests. If a binding mismatch persists after upgrading, compare only the nonsecret protocol version, tenant/site IDs and connector UUID, then perform a fresh one-time-token pairing as needed; never disable signature verification.
