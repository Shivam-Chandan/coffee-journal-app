# Personal ChatGPT OAuth setup

For Claude, see [Connect Claude with OAuth](claude-oauth-setup.md). Both
assistants share this server but are registered as separate OAuth clients with
their own callbacks, grants and revocation.

Status: implemented and tested locally. Actual ChatGPT registration, cloud Dev/
Test verification and production migration require the plugin's exact callback
and client settings. Do not revoke the working personal credential until the
real OAuth connection is accepted.

## Implementation choice

The compatibility spike evaluated Simple OAuth 6.1.3. Its Drupal 11 and PKCE
support are useful, but its default JWT audience is the consumer/client rather
than the MCP resource, its authentication provider is site-wide, and this pilot
needs stable revocable grant families and refresh-reuse handling.

`coffee_journal_oauth` therefore uses **League OAuth2 Server 9.4**, the maintained
engine underlying Simple OAuth, directly. League implements code exchange,
PKCE validation, code encryption, refresh encryption and rotation. Custom Drupal
adapters provide one predefined client, consent, resource-audienced JWT claims,
grant persistence and journal-only access. No contributed/core code is modified.

Only authorization-code and refresh grants are enabled. There is no password
grant, client-credentials grant, open registration endpoint, CIMD fetcher or
automatic authorization. If the actual ChatGPT builder requires CIMD/DCR instead
of predefined clients, that requires additional implementation before connecting.

## Before activation: obtain these non-secret values

From the existing plugin's MCP management page:

1. Copy the **exact redirect URI** displayed for that connection. Do not guess a
   stable or callback-ID-specific URL from documentation examples.
2. Confirm **predefined/manual OAuth client configuration** is supported.
3. Confirm whether it accepts a public client (`none`, with PKCE) or requires a
   secret using `client_secret_post` or `client_secret_basic`.
4. Confirm the plugin calls the remote MCP endpoint directly. Uploaded local tool
   runtimes that still use a pasted token must be updated separately.

No OpenAI inference API key is required. Drupal remains the account/login authority.

## Runtime settings

Configure each Cloud environment independently. Use its real canonical Drupal
HTTPS origin for `COFFEE_OAUTH_ISSUER`; do not reuse production issuer/keys on Dev.

| Variable | Required value |
|---|---|
| `COFFEE_CONNECTOR_ACCOUNT_UUID` | Existing allowed Drupal account UUID |
| `COFFEE_OAUTH_ENABLED` | `1` only after the remaining settings are configured |
| `COFFEE_OAUTH_ISSUER` | Canonical HTTPS Drupal origin, no trailing slash/path |
| `COFFEE_OAUTH_CHATGPT_CLIENT_ID` | Agreed predefined client ID, 8–128 letters/digits/`.`/`_`/`:`/`-` |
| `COFFEE_OAUTH_CHATGPT_REDIRECT_URIS` | `https://chatgpt.com/connector_platform_oauth_redirect` |
| `COFFEE_OAUTH_CHATGPT_CLIENT_SECRET` | Only for a confidential client; absent for PKCE public client |
| `COFFEE_OAUTH_CLAUDE_CLIENT_ID` | Claude's client ID; see [Claude setup](claude-oauth-setup.md) |
| `COFFEE_OAUTH_CLAUDE_REDIRECT_URIS` | `https://claude.ai/api/mcp/auth_callback` |
| `COFFEE_OAUTH_PRIVATE_KEY_PATH` | Absolute readable RSA private-key path outside docroot |
| `COFFEE_OAUTH_PUBLIC_KEY_PATH` | Absolute readable matching public-key path outside docroot |
| `COFFEE_OAUTH_PREVIOUS_PUBLIC_KEY_PATH` | Optional old public key during rotation overlap |
| `COFFEE_OAUTH_ENCRYPTION_KEY` | Independent cryptographically random secret, at least 64 characters |

Resolve the environment's persistent private-files directory and store signing
keys there with restricted permissions. Generate an RSA key pair using OpenSSL
(3072 bits recommended), not during a web request. Keep the private file readable
only by the required application/server operators. `coffee:oauth-check` validates
the paths/configuration without printing secrets.

Client secrets and encryption secrets belong in runtime secret settings, never
Drupal config exports, git, frontend variables, browser storage or chat messages.
The private key must never be stored under the web root. Use an independent
encryption secret rather than repurposing Drupal's hash salt.

## Deployment and local setup

The module is listed in exported `core.extension.yml` so later config imports do
not uninstall it and delete grant storage. Its OAuth feature flag defaults off.
The existing deploy hook enables configured custom modules, applies updates,
imports config and rebuilds caches. It creates grant/token tables additively.

For a local installation before config import:

```sh
composer install
drush en coffee_journal_oauth --yes
drush updatedb --yes
drush cr
drush coffee:oauth-check
```

`coffee:oauth-check` reports disabled when the feature flag is off. After runtime
configuration, it verifies the server can be built and the active pilot exists.
This is not a substitute for a real code exchange or ChatGPT acceptance test.

Keep credentials/tokens out of captured CLI output. Do not use verbose request
logging while exercising authorization/token endpoints, and ensure infrastructure
logs do not capture token bodies or authorization headers.

## OAuth endpoints and discovery

For issuer `https://YOUR-DRUPAL-ORIGIN`:

| Endpoint | Behavior |
|---|---|
| `GET /.well-known/oauth-protected-resource` | Exact MCP resource, issuer and scopes |
| `GET /.well-known/oauth-authorization-server` | Actual endpoints, S256 and client-auth methods |
| `GET/POST /coffee/oauth/authorize` | Validated login and explicit Drupal consent |
| `POST /coffee/oauth/token` | Form-encoded authorization-code or refresh exchange |
| `/api/integrations/v1/mcp` | Existing MCP tools, OAuth discovery/challenges and authenticated calls |
| `GET /api/v1/me/connections/chatgpt` | Browser-owned grant status |
| `DELETE /api/v1/me/connections/chatgpt` | Browser-owned, CSRF-protected grant revocation |

The canonical resource is exactly:

```text
https://YOUR-DRUPAL-ORIGIN/api/integrations/v1/mcp
```

The client must send that `resource` on authorization, code exchange and refresh.
Authorization requires `response_type=code`, the registered client and exact
redirect, a state value, `code_challenge_method=S256`, and a valid challenge.

The server advertises `authorization_response_iss_parameter_supported` and
returns the RFC 9207 `iss` parameter on every authorization redirect, including
denials and errors. OpenAI documents that this qualifies a server for the
**stable** redirect URI `https://chatgpt.com/connector_platform_oauth_redirect`
instead of a per-connection callback that can only be read from the management
page. Confirm the redirect URI shown for your connection still matches before
relying on it.

The server also accepts **Client ID Metadata Documents (CIMD)**, which is how
ChatGPT actually identifies itself: it presents an HTTPS document URL as its
`client_id` rather than a pre-registered string. Only exact allowlisted document
URLs are ever fetched, the document must claim the same `client_id` it was
presented as, and its callbacks are honoured only when this deployment already
registered them. CIMD clients are public and authenticate with PKCE, never a
secret. A predefined `COFFEE_OAUTH_CHATGPT_CLIENT_ID` remains supported, and
`COFFEE_OAUTH_CHATGPT_REDIRECT_URIS` must still list the callback either way.

Metadata deliberately does **not** advertise DCR or OIDC scopes. Token requests are server-to-server form-encoded POSTs,
not browser-session requests; the token route does not accept cookie auth.

## Connection lifecycle

- Scopes: `beans:read`, `beans:create` and `beans:update`.
- Access token lifetime: 15 minutes.
- Code lifetime: 5 minutes, single use.
- Grant family lifetime: at most 30 days from creation.
- Refresh tokens rotate; valid reuse of a consumed refresh token revokes its
  entire grant family. Revoked/expired grants reject access immediately.
- Code/refresh consumption is serialized and row-locked within a DB transaction.
  Stored token records contain only hashed identifiers and revocation metadata,
  not raw bearer tokens, refresh secrets or authorization codes.
- The verified grant ID stays stable across refresh and same-grant consent, so
  an interrupted create can retry with its original `request_id` without creating
  another coffee. Disconnect/reconnect creates a new family; do not automatically
  replay pending writes across distinct grants.
- Grants check the current pilot account, active status, client, scopes and expiry
  on every authorized request. OAuth tokens are accepted only on the MCP route,
  not as Drupal browser sessions or generic entity API credentials.
- Anonymous MCP initialization/tool discovery exposes only public definitions.
  Protected invocations receive OAuth challenges, never journal/profile data.
  Discovery has a separate IP rate limit and session namespace. After linking,
  a client using an old anonymous session must initialize a new authenticated
  session when the server returns session-not-found.
- `get_profile` returns the authenticated user's stable Drupal UUID. Name/email
  are display metadata; unavailable optional email values are omitted.
- React reads server grant status, refetches on focus, and provides Disconnect.
  An active grant is not proof that the plugin remains installed in ChatGPT.
  Removing a plugin in ChatGPT does not notify Drupal unless the platform provides
  a supported revocation signal. Use Drupal Disconnect to revoke locally.

The regular Drupal login may be separate from the Vercel app's browser session.
OAuth may ask the owner to sign into Drupal even when the React app is signed in.
Do not copy session cookies between origins to bypass this.

## Acceptance and production cutover

1. On Dev, use a protocol harness/Inspector to verify metadata, S256, exact callback,
   code exchange, token scopes/resource, refresh and revocation.
2. Exercise the real Drupal login and consent page. Invalid clients/callbacks must
   not receive codes. Declining consent must not create an authorized connection.
3. Configure the actual ChatGPT plugin with Dev OAuth settings. Verify discovery,
   login, consent, stable profile identity, read and a user-confirmed coffee save.
4. Verify a repeated request and a retry after refresh return the same coffee.
   Verify blocked accounts, wrong scopes, consumed codes and revoked grants fail.
5. Verify disconnect/reconnect from the app and the supported ChatGPT mobile
   client. Confirm **Added via ChatGPT** survives edits and disconnect.
6. Promote the additive backend/UI through Test and production, configure the
   production issuer/client/callback/keys, and repeat the owner's actual journey.
7. Revoke the old ChatGPT `cj_...` credential after acceptance:

   ```sh
   drush coffee:connection-revoke CONNECTION_ID
   ```

Other providers' personal credentials remain independent. Do not label an old
personal token as an OAuth-linked account or revoke unrelated connections.

## Rotation and rollback

For RSA rotation, retain the previous public key temporarily in
`COFFEE_OAUTH_PREVIOUS_PUBLIC_KEY_PATH`, switch the private/current public pair,
then remove the old public key after at least the access-token lifetime and
operational clock allowance. New refresh/code exchanges sign with the new key.
Changing the encryption secret invalidates outstanding encrypted codes/refresh
tokens; plan reauthorization rather than assuming transparent rotation.

To stop OAuth, set `COFFEE_OAUTH_ENABLED=0` and rebuild caches. Keep the module and
database tables installed so grant/provenance history is retained. Do not
uninstall it as a rollback shortcut. Existing legacy credential handling remains
separate; rollback does not restore a revoked personal token. Disable or adjust
the plugin's OAuth connection explicitly when coordinating rollback.

## Verification commands

```sh
SIMPLETEST_DB=sqlite://localhost/:memory: php vendor/bin/phpunit --no-configuration --bootstrap docroot/core/tests/bootstrap.php docroot/modules/custom/coffee_journal_api/tests/src/Kernel docroot/modules/custom/coffee_journal_oauth/tests/src/Kernel
# From frontend/ using Node 22:
npm test
DRUPAL_ORIGIN=https://eeschandan1dev.prod.acquia-sites.com npm run build:vercel
```

Actual ChatGPT registration/callback details are still required before this can
be considered an established ChatGPT connection.
