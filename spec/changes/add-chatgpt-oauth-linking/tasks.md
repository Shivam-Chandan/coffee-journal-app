# OAuth implementation checklist

Status: local backend/UI implementation verified. Actual ChatGPT client settings
and cloud Dev/Test/production acceptance remain pending. See [execution.md](execution.md).

1. [ ] **Confirm the ChatGPT integration contract.** Capture exact redirect URI,
   client-registration/auth methods and plugin tool transport. Verify currently
   supported OAuth linking behavior from official docs and the actual builder.
2. [x] **Run the maintained-provider compatibility spike.** Evaluate Simple OAuth
   and dependencies against installed Drupal/PHP, mandatory PKCE, resource binding,
   discovery, refresh rotation/replay handling and revocation. Produce a capability
   matrix and prove a Dev code exchange; resolve gaps before committing to a stack.
3. [x] **Finalize architecture and grant model.** Record issuer/resource URLs,
   client method, endpoint contracts, lifetimes, stable connection IDs, anonymous
   discovery limits, existing-token migration and feature flags. Define token/key
   storage and secret delivery with no secrets in exported config.
4. [ ] **Install/configure the authorization server on Dev.** Add supported
   dependencies and exported config, per-environment signing keys, scoped client,
   exact redirects and authorization-code/refresh grants. Verify install/update
   ordering and existing browser login compatibility.
5. [x] **Implement owner-limited login and consent.** Preserve validated OAuth
   request context through Drupal login; show requested permissions and approval/
   denial. Enforce pilot account, active status, scope limits, redirect validation,
   PKCE S256 and one-time codes. Use Form API/render arrays and theme templates.
6. [x] **Publish discovery and resource validation.** Add protected-resource and
   authorization-server metadata plus challenges. Validate resource/audience at
   authorization, exchange, refresh and resource access. Advertise only implemented
   issuer-identification/registration capabilities.
7. [x] **Integrate OAuth connection context.** Resolve validated grants into the
   existing owner/scopes/provider context. Enforce revocation/account status and
   authentication route isolation. Preserve grant identity across refresh so
   idempotency, provenance and MCP session isolation remain correct.
8. [x] **Implement MCP linking and profile identity.** Add bounded anonymous
   initialization/tool-definition discovery, per-tool OAuth declarations, runtime
   auth challenges and authenticated `get_profile`. Verify actual SDK wire output,
   session transition, JSON/SSE behavior and stable UUID identity.
9. [x] **Add profile status and disconnect controls.** Implement owner-scoped
   browser status/revocation contracts with CSRF/no-store behavior. Update React
   states, refetch on return/focus, accessible consent guidance and reconnect flow.
   Disconnect revokes the grant and refresh family, not just the local UI state.
10. [x] **Run protocol/access/regression verification.** Cover success/denial,
    missing/wrong/plain PKCE, reused/expired code, redirect mismatch, scope/resource
    mismatch, forged/expired token, refresh replay, blocked/non-pilot accounts,
    revoked grants, cross-client/session isolation, anonymous no-data behavior,
    retry after refresh, unchanged manual creation and immutable AI attribution.
    Run frontend checks and verify zero Vercel functions.
11. [ ] **Verify actual ChatGPT linking on Dev/Test.** Configure the supported
    plugin client; complete login/consent/read/confirmed-create, token refresh,
    disconnect/reconnect and missing-scope journeys. Verify link behavior and
    supported mobile flow; record evidence rather than only an API-client result.
12. [ ] **Release and migrate production.** Deploy additive backend then UI,
    configure the plugin's production OAuth client, verify the owner's journey,
    revoke the old ChatGPT personal token, and confirm other provider credentials
    remain independently scoped. Rehearse disabling OAuth access without data loss.
13. [ ] **Finish documentation and acceptance.** Update plugin setup, operational
    rotation/revocation/rollback instructions and existing bearer-token guidance.
    Record deployed revisions, client acceptance evidence and known limitations.
    Archive only after the required live linking and migration checks pass.

## Milestones

- **A — Feasibility:** tasks 1–3; verified provider and exact ChatGPT contract.
- **B — Backend:** tasks 4–8; protocol/client harness works on Dev.
- **C — Account experience:** tasks 9–11; real ChatGPT linking verified.
- **D — Production:** tasks 12–13; OAuth enabled and old ChatGPT token revoked.

## Partial tasks and gating inputs

- **1:** Official client contract researched. The actual plugin's exact callback,
  predefined-client support and client-authentication settings are still needed.
- **4:** Dependencies, exported module selection, private key settings and local
  schema installation/update are implemented. Local protocol/HTTP fixtures work;
  cloud Dev configuration and actual-client registration are pending.
- **13:** Setup, rotation, rollback and migration runbook delivered in
  `docs/ai-connectors/oauth-setup.md`; deployed/client acceptance evidence remains
  pending. No production OAuth activation or old-token revocation has occurred.
- **Stack decision:** use the maintained League OAuth2 Server directly in the
  journal-specific module rather than enable Simple OAuth's global provider.
  The reasoning and evidence are recorded in `execution.md`.
