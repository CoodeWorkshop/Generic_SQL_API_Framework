# Managed API keys

Managed API keys use `X-API-Key` and the same principal and authorization path as sessions. Create and manage them from **Admin Console → API Keys**.

Creation returns a `gsk_...` secret exactly once. Storage contains only a password hash and a safe fingerprint; raw keys are never stored, listed, or logged. Metadata includes name, owner, roles, enabled/revoked state, creation time, and last-used time.

Disabled keys can be re-enabled. Revocation is permanent. Key and enabled-owner state are loaded during every authentication, so changes take effect immediately without frontend cooperation. Failures do not disclose whether a key exists.

The legacy `GENERIC_SQL_API_KEY` remains supported. Its permissions come from `legacyApiKeyRoles` (Viewer by default), so it is not an implicit administrator credential.
