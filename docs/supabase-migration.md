# EditorAI shared Supabase preparation

Keep the original stopped runtime, SQLite database, Docker images, application
key and media available for recovery. Do not start queue workers, schedulers,
transcription, AI calls or broadcasts as a migration smoke test.

Fresh PostgreSQL installations accept the `uploaded` project status used by the
upload controller and stored legacy data. `DB_SCHEMA=editorai` selects an isolated
schema; use a restricted non-superuser application login, outside public
PostgREST exposure. Shared DB changes follow Mind's reviewed migration process.

On an isolated restored copy, run the Laravel migrations into an EMPTY PostgreSQL
schema, then use `tools/prepare_sqlite_import.py` to prepare a private transaction.
The script refuses unknown tables, integrity failures, decimal precision loss,
different migrations and nonempty destination tables. It preserves IDs, passwords,
personal token hashes, JSON, nullable ownership and SQLite sequence high-water
marks. Import SQL contains private data: keep it outside Git and terminal output.
Execute only on the rehearsed destination with `ON_ERROR_STOP=1`; compare all
rows semantically, sequences, foreign keys, JSON, decimal values and media hashes.

The ownership middleware rejects foreign and ownerless projects and clips that
belong to another project. New project creation now persists `user_id`. Existing
orphan projects remain orphaned. Assign their ownership only after a verified
owner mapping; never infer it from a lone local account or matching email.

## Shared sign-in gate

Use the existing shared Supabase Auth issuer and the central login, session and
logout contract coordinated with the other applications. Proposed recognizable
EditorAI endpoint: `https://editorai.tural.ai`, callback `/auth/callback`; the domain
and callback must be confirmed before enabling access. Bind the verified shared
Auth subject UUID explicitly to a local user ID. Preserve all local IDs and
permissions. Email alone is insufficient. Test server token verification, expired,
wrong issuer/audience, unmapped identities, cross-project/clip access and logout.

This branch prepares data/schema and ownership safety. It does not implement SSO,
assign orphan projects, provision live credentials, modify shared Auth or activate
the stopped application. A code write/release credential is required to publish
the changes to the existing repository.

## OAuth 2.1 integration adapter

`PkceHandshake` prepares an authorization-code flow with S256, exact callback,
short-lived state and nonce, then consumes the pending context before exchange.
Persist pending server-side and use locked/atomic session storage in callback
routes. Send only the authorization URL to the browser. Exchange codes and refresh
tokens server-side against the coordinator-approved fixed token endpoint; do not
follow provider redirects or log token responses. Client authentication method
and credentials must match its registration.

`OidcIdentityVerifier` verifies ID/access token signatures using trusted issuer
JWKS with RS256/ES256, issuer, expiry, subject UUID, client/audience, nonce and
optional access-token hash. It returns only issuer/subject for explicit binding
to a local user. No lookup or merging by email. It rejects HMAC/service-role
tokens; `openid` therefore needs confirmed asymmetric signing and public JWKS.

These adapters are dormant protocol interfaces, not login endpoints. Activation
still requires registered app clients/callbacks, the existing shared consent UI,
host-only Secure HttpOnly SameSite=Lax app sessions, CSRF protection for writes,
encrypted server-side refresh tokens, session rotation on login/linking and a
coordinated logout/revocation contract. Use unique session cookies per editor.
Identity linking requires proof of both sessions plus explicit consent and a
unique `(issuer, subject)` binding; preserve existing local user IDs/permissions.
Never return OAuth tokens in callback URLs, browser storage or API responses.
The test fixtures generate signing keys only in memory and make no provider calls.

Protocol reference: https://supabase.com/docs/guides/auth/oauth-server/oauth-flows
