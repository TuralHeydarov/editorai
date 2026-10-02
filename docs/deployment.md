# editorai: reviewed Netcup release prerequisites

Release only from personal TuralHeydarov/editorai; source provenance remains in
the private migration passport. The container job builds immutable SHA-tagged
images on CI without runtime secrets. The application runner requires the
existing shared Supabase network and protected per-app runtime configuration.

`deploy/netcup.compose.yml` has explicit profiles: web and, for AlMotion only,
sidecar. No profile is selected by default. It declares no workers, scheduler
or render/generation jobs. Runtime credentials do not run migrations on startup.
EditorAI stays stopped until its web release and ownership guards are verified.
Schema CI produces private immutable artifacts. The separate guarded release is default-disabled.

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

The app-only deployment interface below is the normal target release route.
Its provisioning receipt and protected environment must be verified before production launch. Do
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


## Prepared app-only release interface (not provisioned)

`guarded-release.yml` is default-disabled: `ENABLE_TARGET_RELEASE` is absent/false.
It accepts only a successful **current main push** schema-isolation run, never a
PR/fork build. The sender downloads its immutable SHA artifacts with the job's
own read-only GitHub token and checks every image digest before transfer. It sends only a small non-secret
header first, then waits for the exact target admission acknowledgement. NO-GO
refuses before any image upload, Docker load or service start.
`netcup-editorai-production` is a separate protected environment. No organization,
CF/Pay key, root private key, other app token or service credential is reused.

The future target login is `editorai_release`. Its only accepted SSH key must use
`restrict,command="sudo -n /usr/bin/python3 /usr/local/lib/tural-app-release/editorai/receive.py --app editorai"`.
No interactive command, scp/sftp, forwarding, PTY, user rc, Docker group or broad
sudo is permitted. Sudoers authorizes precisely that Python program and literal
app argument, with env_reset/NOSETENV and root home. The reviewed receiver/package
must be root-owned and not writable by the CI/login identity.

The receiver reads only `/etc/tural-app-release/editorai/admission.json`, runtime.env,
optional AlMotion sidecar.env and global-slot.json. Files are root-only and every
ancestor is root-owned, non-symlink, not group/world writable. An explicit exact
SHA admission expires within one hour and requires GO + evidence for Auth, ACL,
memory, final restored data, owner-media acceptance and this target release.
Global slot must name the same app/SHA and be fresh. No file or missing/NO-GO
receipt authorizes itself; examples deliberately fail. The **global memory deficit
and actual Auth NO-GO remain blockers** even when individual checks/CI are green.
Runtime MemAvailable must also cover this app allowance plus3GiB reserve.

Each transferred archive must match the root-admitted compressed digest; its
single image tag and OCI revision must be exact app/component/SHA, backend user
www-data. Bounded streaming, private staging, digest validation, disk check and
cross-app rejection happen before Docker loads. One global lock serializes these
receivers. Unknown existing app containers without a saved prior release refuse.
Compose is root-pinned by SHA256, only fixed own profiles, no builds/pulls/migrations,
no Caddy/DNS or source changes. CPU/PID/no-extra-swap limits remain in compose.
A launch is only loopback service start, not public cutover or app-login acceptance.
Basic Laravel/SPA health cannot replace owner/cross-tenant/provider/SSO acceptance.
On launch/health failure restore only the known previous own-app image SHA, or
return the new own project to stopped if there was no previous deployment. Never
remove bind volumes/images/source or stop other services.

### Exact one-time provisioning dependency

An authorized infrastructure owner must provision **this reviewed app-only
receiver binding**, not grant this agent another identity: root-owned receive.py
and pinned netcup.compose.yml under `/usr/local/lib/tural-app-release/editorai`;
root-only `/etc/tural-app-release` and app directory; the locked, restricted
`editorai_release` account with the forced command and exact sudoers rule; a **new
key for this app only** stored in its protected GitHub environment as
TARGET_SSH_PRIVATE_KEY, plus verified public server host pin TARGET_KNOWN_HOSTS.
Do not paste private keys into chat or copy an existing root/Pay/CF key.
The restored own storage must exist at `/opt/stacks/editorai/storage/app`; runtime.env
must contain only the issued own DB role/OAuth settings and preserved APP_KEY.
The actual approved existing Supabase Docker network belongs in admission.

Owner/central release process then writes exact main SHA, **inner image.tar.gz
digests**, pinned compose digest, accepted GO evidence and short expiry into
admission.json/global-slot.json (examples are not grants). Only after global
Auth/resource/data/slot acceptance may the owner enable ENABLE_TARGET_RELEASE
and the protected environment route. Bootstrap is permitted only through the documented reviewed main package with
confirmed repository ADMIN and existing authorized server root transport. It
creates an application deployment identity, never a Brain/assistant grant. Do
not activate services or mark any admission GO while global gates are unresolved.
A fresh accepted SHA may then be dispatched with its green main run ID; future
main success events follow the same mandatory admission. Absent/expired scope
refuses rather than silently invoking a broader deployment path.


The one-time normal bootstrap is `python3 deploy/bootstrap.py --app editorai --revision <reviewed-main-SHA> --public-key <new-app-key.pub>`
from a private, exact-main manifest package (first run with --check). The package
manifest hashes only bootstrap.py, receive.py, compose and default-NO-GO example;
source/runtime env or application data are excluded. Existing accounts/paths refuse
rather than overwrite. It installs a root-owned, non-writable login home and fixed
key route, exact NOSETENV sudo command and NO-GO admission. It starts no service,
creates no global slot/runtime.env/DB/Auth/Brain grant, and changes no existing SSH
trust or other user's settings. Save bootstrap receipt before any repeat; a partial
failure is a reviewable provisioning state, never grounds to overwrite unknown data.

After installation the new app key must demonstrate a NO-GO handshake refusal with
zero image bytes uploaded. A denied handshake cannot be reported as production
release. Actual root admissions, own runtime configuration and global launch gates
remain mandatory. Only the app's protected environment receives its new private
key; public host key comes from the already verified target transport. If repository
environment protection is unavailable on the current plan, report that exact gate;
never move the key to an unprotected fallback environment or buy an upgrade.

Private uploaded videos keep their original physical storage and source_url, but only an owned authenticated media route may serve them. Range/HEAD revalidate the session on every request; no public storage alias or query token is accepted. Legacy playback uses a five-minute host-only encrypted lease tied to a live auth token; full SSO uses the actual shared-session guard. Foreign and NULL-owned projects are denied and never reassigned. Upload transcription, chat transcription/auto-analysis without SRT, and render are explicitly rejected before external media-fetch/provider calls or job status mutation. Existing transcript analysis and private viewing/timeline editing remain available. Full private-asset external rendering is not delivered by this change.
