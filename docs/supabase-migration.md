# EditorAI: shared Supabase release contract

The application remains on its existing source runtime until the coordinated
cutover. `SHARED_SSO_ENABLED=false` and `SHARED_SSO_LINKING_ENABLED=false` by default. Code preparation and fixture CI
do not confirm a live shared database migration, OAuth registration or deployment.
Release repository: `TuralHeydarov/editorai`. Historical source remotes and data
remain intact; no organization repository is changed by this release.

## Database and runtime roles

Use the existing Supabase PostgreSQL with `DB_CONNECTION=pgsql` and
`DB_SCHEMA=editorai`. The default remains `public` for the unchanged source.
`editorai_migrator` owns only the application schema and performs reviewed DDL.
`editorai_app` is a non-superuser runtime login with no migrator membership, own
schema USAGE, table SELECT/INSERT/UPDATE/DELETE and sequence USAGE/SELECT.
Grant future objects from the actual migrator owner. Do not run migrations as
the runtime role and do not grant auth/brain/public table access or service keys.

The shared release additionally grants USAGE tural_auth and EXECUTE on
`tural_auth.session_active(uuid,uuid)` to the runtime role. The function is
called by qualified name for each shared browser API request. False ends the
local session; missing function/database access returns503 and fails closed.
NOINHERIT alone cannot cancel PUBLIC privileges. A reviewed shared migration
must preserve existing actors' legitimate effective privileges and rehearse
denial of public/auth/brain/other-app data and own/public DDL. Our empty vanilla
PG15 fixture explicitly revokes PUBLIC schema privileges; it is not a proof
of the actual shared stack's ACL/RLS. Never change Mind ACL from this repo.

## Exact OAuth and session contract

Implemented callback: `https://editorai.tural.ai/api/auth/sso/callback`.
The earlier `/auth/callback` proposal is superseded. Register an actual separate
confidential OAuth client (client_secret_basic) only after route/origin/TLS
verification. Expected issuer `https://id.tural.ai/auth/v1` must be confirmed
with a real signed test token before enabling SSO. Fixed endpoints relative to
that issuer: `/oauth/authorize`, `/oauth/token`, `/.well-known/jwks.json`, and
`/logout?scope=global`. No dynamic registration or second IdP.

Authorization code + S256 PKCE requests openid/email/profile. One-use pending
state/verifier/nonce lives under a locked server session for5minutes. Validate
ES256/RS256 signatures using exactly one matching trusted JWKS key, issuer,
expiry/issued time, ID audience=client UUID and nonce, access audience=authenticated,
role=authenticated, client_id, UUID subject/session_id and equal ID/access subject.
Refresh must retain the subject and session ID. Do not trust email as ownership.

Use database or Redis sessions with encryption, `__Host-editorai-session` host-only Secure
HttpOnly SameSite=Lax cookie, path/. Tokens stay encrypted server-side, never in
browser storage, API response or final redirect. The browser fetches profile
using cookies and adds CSRF to API writes/uploads; old login/register/Google
routes are unavailable while shared mode is active. API Bearer tokens cannot
bypass the shared browser gate. Laravel's database cache/session lock tables
must be provisioned inside the app schema. Preserve the original APP_KEY for
encrypted settings; never generate another key during restore/deploy.

After the shared release, a bounded pre-link phase may set linking_enabled
while full SSO stays off. Existing local sign-in/register remains available;
only old-session/password-confirmed linking starts the shared flow. Generic
shared login and onboarding stay closed until the full-mode gate.

Implemented UI/endpoints: GET `/api/auth/sso/login`, status, start, callback,
onboard and account; POST link, onboard and logout. An existing application
account requires its still-valid old `auth-token`, fresh password, explicit
link confirmation and a new shared sign-in. Unique bindings preserve local user
IDs and existing business permissions. Another binding returns409. Unknown
shared subjects may explicitly create a new regular app account with a verified
email; an existing email collision prompts linking and grants nothing. No
automatic email merge, admin role, old workspace or old project assignment.

The account page offers local sign-out and global sign-out separately. A global
provider failure returns503 after clearing the local session, without claiming
shared revocation. Global revocation is checked on each connected app's next
server request. Dedicated machine/API keys retain their existing lifecycle;
shared logout does not promise their revocation.

## Release and verification

Fixture CI exercises real JWT/PKCE/token HTTP/session/mapping code with offline
provider responses and a mocked shared session_active function. It also checks
CSRF, nonce/state/replay, cross-client/refresh binding, conflicting owner links,
explicit onboarding, denied legacy browser tokens and global logout. Browser
transport tests preserve the disabled-mode legacy path and reject old-token
fallback in shared mode. PHP/Node fixtures contain no production credentials.

Before ON: common reviewed Auth/consent/client/session_active/ACL release; actual
signed claims; live owner linking, negative user and global-logout/refresh e2e;
reviewed migration/restoration; target runtime packaging/resource reservation;
normal automatic release credentials and approved serialized Caddy/DNS slot.
Final data freeze/delta and retained-media smoke are required. AlMotion browser
IndexedDB/OPFS content is not covered by server backups. EditorAI's two historical
NULL-owned projects stay unassigned and inaccessible until ownership is proven.

Reference: https://supabase.com/docs/guides/auth/oauth-server/oauth-flows
