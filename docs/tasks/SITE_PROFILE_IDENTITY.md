# Site Profile Identity & Duplicate URL Policy

## Decision
A WordPress URL is **not** a unique site-profile identity.

The application must allow the same normalized URL to be registered multiple times when the user wants separate connection profiles, credentials, or operational settings.

## Required behavior
- Add the same URL again: **allowed**.
- Each registration receives a new `Site.Id`.
- Each profile may have its own WordPress username/Application Password.
- Soft-deleting one profile must not block creating another profile for the same URL.
- Different authenticated owners may also register the same URL independently.
- URL normalization remains enabled for protocol/host/path cleanup.
- Duplicate blocking must not exist at the application layer or database index layer.

## Data-layer requirement
The old global unique `IX_Sites_SiteUrl` index is removed and replaced with a non-unique `(OwnerUserId, SiteUrl)` lookup index.

## QA acceptance criteria
1. Register `https://notonlybook.com` as profile A.
2. Register `https://notonlybook.com/` as profile B with a different name/credentials.
3. Both profiles appear in `/sites` and have different IDs.
4. Saving credentials on profile B does not alter profile A.
5. Delete profile A; profile B remains usable.
6. Register the URL again after deletion; a new profile is created.
7. Verify another authenticated owner can register the same URL.
