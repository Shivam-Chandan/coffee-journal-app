# OAuth implementation record

Status: implemented locally; actual ChatGPT/cloud Dev/Test/prod acceptance remains
blocked on client settings and is not claimed complete.

## Compatibility decision

- Evaluated Simple OAuth 6.1.3 against Drupal 11 and its League 9 engine.
- Confirmed PKCE/authorization-code/refresh support, but its default token audience
  is the consumer rather than the MCP resource and its auth provider is global.
- Selected **League OAuth2 Server 9.4.1 directly** in an isolated custom module.
  This retains maintained OAuth protocol/cryptography while making client/resource
  binding, pilot restrictions, family revocation and route isolation explicit.
- Simple OAuth and consumers were removed after the spike; neither is enabled or
  modified. Composer adds League and its required libraries without updating
  existing package versions.
- First release supports a predefined client only. CIMD/DCR is not advertised or
  implemented. The actual plugin must support manual client configuration before
  this implementation is selected for its live connection.

## Delivered

- `coffee_journal_oauth` with public discovery, explicit Drupal Form API consent,
  authorization-code + mandatory PKCE S256, encrypted code/refresh exchange and
  resource-audienced signed access tokens.
- Stable grant families, hashed token-identifier storage, atomic consumption,
  refresh-reuse family revocation, immediate access revocation and expiry.
- Pilot-account/blocked-account checks, exact client/redirect/resource validation,
  scoped MCP-only OAuth authentication and per-environment secret/key settings.
- Bounded anonymous MCP discovery, per-tool OAuth policy/challenges, authenticated
  stable `get_profile`, grant-isolated sessions and existing journal ownership/
  validation/provenance/idempotency reuse.
- Browser connection-status/disconnect contracts and React status/error/reconnect
  controls. Existing user-authored iPhone launcher behavior was preserved.
- Signing-key rotation overlap and `coffee:oauth-check` configuration validation.
- Module selected in exported extensions to avoid data-dropping uninstall during
  later config imports; runtime OAuth remains disabled by default.
- CI includes both API and OAuth kernel suites. Setup/runbook:
  `docs/ai-connectors/oauth-setup.md`.

## Verification evidence

- Isolated SQLite kernel suites: **26 tests / 453 assertions** pass, covering
  original journal behavior plus PKCE/code reuse/expiry, refresh replay, issuer/
  audience/client/time validation, blocked/changed pilot accounts, anonymous MCP
  challenges, stable profile identity, optional missing email, key rotation,
  consent ownership and browser-route/CSRF boundaries.
- Existing API/security unit suite: **70 tests / 304 assertions** pass.
- Frontend suite: **23 tests** pass, including server-authoritative status,
  status-error semantics and CSRF-protected disconnect UI behavior.
- Frontend production build/typecheck passes; generated Vercel output has **zero
  functions**.
- API coding-standard gate passes after dependency-injecting the optional OAuth
  connection-status integration.
- Local real-HTTP/MySQL harness passed metadata discovery, Drupal login, actual
  CSRF-protected consent submission, callback/state, form-encoded code exchange,
  authenticated MCP profile, browser grant status, disconnect CSRF rejection and
  immediate access/refresh revocation. A four-worker run exchanged one code in
  four simultaneous requests and produced exactly one successful token pair.
  Temporary users/grants/tokens were cleaned up and no actual ChatGPT callback
  was followed.
- HTTP verification found that the authorization page needed `_no_big_pipe` for
  deterministic consent rendering in the current theme, and that optional absent
  profile email must be omitted rather than returned as null. Both were corrected.
- Private test keys were generated outside the web root for the local harness;
  no production credentials/keys were created or changed.

## Remaining gates

1. Obtain exact ChatGPT callback, predefined client support and token auth method.
2. Configure independent Cloud Dev secrets/issuer/client and verify the actual
   ChatGPT web/mobile linking flow, not only a local protocol harness.
3. Run Test/production acceptance and retire the old ChatGPT credential only after
   success. No production OAuth activation or legacy-token revocation has occurred.
