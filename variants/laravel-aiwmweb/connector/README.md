# AIMW Connector v0.1.0

Install the `aimw-connector` directory as a WordPress plugin. An administrator pairs it by POSTing `platform_url` and the one-time `pairing_token` to `/wp-json/aimw/v1/pair` while authenticated to WordPress.

The connector accepts only versioned semantic REST operations. It uses HMAC-SHA256, timestamp/nonce replay protection, request/correlation/operation IDs, explicit scopes, WordPress capability checks for pairing, WordPress APIs for reads/writes, idempotent execution receipts, and a bounded local audit. It provides no raw SQL, arbitrary PHP, filesystem, or command execution surface.
