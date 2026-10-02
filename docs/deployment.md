# editorai: reviewed Netcup release prerequisites

Release only from personal TuralHeydarov/editorai; source provenance remains in
the private migration passport. The container job builds immutable SHA-tagged
images on CI without runtime secrets. The application runner requires the
existing shared Supabase network and protected per-app runtime configuration.

`deploy/netcup.compose.yml` has explicit profiles: web and, for AlMotion only,
sidecar. No profile is selected by default. It declares no workers, scheduler
or render/generation jobs. Runtime credentials do not run migrations on startup.
EditorAI stays stopped until its web release and ownership guards are verified.
No image registry publication or production deployment is performed by CI.

Before any start, the shared owner must provide actual restricted DB role,
OAuth client/config/session function, approved resource reservation and ports,
restored storage, original APP_KEY and the existing Docker network. Each app
needs its own protected runtime env file; never use the shared Supabase service
role or another assistant's key. APP_ENV=production and APP_DEBUG=false are
forced by the target compose. Existing source development env is not a release
configuration. Validate with `docker compose --file deploy/netcup.compose.yml
config --quiet` so private environment values are not printed.

Target ports requested: backend8206/frontend3206/sidecar8790 for AlMotion;
backend8207/frontend2994 for EditorAI. All published binds are loopback. These
are reservation requests, not approval to start or change Caddy/DNS.

A deployment credential/automatic target release has not yet been configured.
Owner/coordinator must establish the normal repo CI/CD release with its own
scoped credential and serialized deployment gate before production launch. Do
not copy a personal root SSH key to CI or use the historical source deploy
script as a target installer. No manual production rollout is authorized by
this prerequisite document.

Before SSO ON, use `SHARED_SSO_LINKING_ENABLED=true` with
`SHARED_SSO_ENABLED=false` only after common release: ordinary legacy login
remains available while users prove the old app session/password and confirm
the new shared identity. Generic shared login/onboarding remains closed in
that phase. After account/linking/negative/revocation acceptance, enable full
SSO with an approved rollout. Preserve historical NULL-owned projects.

Final source freeze/consistent DB+files delta and live verification still
apply. Do not overwrite a fresher existing Netcup instance, delete the source
or report cold copies/CI artifacts as a working deployed application.
