# Connect Claude to the coffee journal with OAuth

Status: implemented and verified locally against a real HTTP server. Production
activation still requires the environment settings below. No account pastes a
token: each person signs in to Drupal and approves access.

Claude's documentation for custom connectors and connector authentication was
checked on November 1, 2026. Recheck it before activation, because Anthropic can
change the callback URL or the supported registration methods.

## How the connection works

```text
Claude  ──1── calls the MCP endpoint without a token
        ──2── receives 401 + resource_metadata pointer
        ──3── reads discovery metadata, finds the Drupal issuer
        ──4── sends you to Drupal to sign in and approve
        ──5── exchanges a one-time code with PKCE
        ──6── calls journal tools with a short-lived access token
```

Drupal stays authoritative for your account, permissions and coffee records.
No Anthropic or OpenAI API key is involved; Claude supplies the conversation.

## What each assistant gets

Each assistant is registered as its own OAuth client with its own callbacks, so
one client's credential or callback can never authorize another's connection.
Grants, refresh families and revocation are tracked per provider, and new
entries keep their own attribution:

| Assistant | Journal labels |
|---|---|
| Claude | **AI · Claude** and **Added via Claude** |
| ChatGPT | **AI · ChatGPT** and **Added via ChatGPT** |

Disconnecting one assistant in the coffee app leaves the other connected.

## Register the Claude client

Claude offers three ways to identify itself. This implementation supports the
**"Use your own OAuth client"** option, where you enter a client ID that Drupal
recognizes. It does **not** implement Dynamic Client Registration (DCR) or Client
ID Metadata Documents (CIMD), and its metadata does not advertise them.

Choose any stable identifier, for example `claude-coffee-journal`. It is not a
secret. Leave the client secret blank so Claude connects as a public PKCE client,
which is what its documentation expects for this option.

### Callback URLs to allow

Claude documents one hosted callback for claude.ai on the web, Desktop, mobile
and Cowork:

```text
https://claude.ai/api/mcp/auth_callback
```

Claude Code uses loopback redirects on a changing port instead. Those are **not**
supported here, because this implementation matches exact HTTPS callbacks and
does not do port-agnostic loopback matching. Use claude.ai for this connection.

Confirm the callback in Claude's current documentation before activation. If it
differs, update the environment setting rather than editing code.

## Configure the environment

Set these on the target Acquia environment. Use its own canonical HTTPS origin;
never reuse production keys or secrets on Dev.

| Variable | Value |
|---|---|
| `COFFEE_CONNECTOR_ACCOUNT_UUID` | The allowed Drupal account UUID |
| `COFFEE_OAUTH_ENABLED` | `1` once the rest is configured |
| `COFFEE_OAUTH_ISSUER` | Canonical HTTPS Drupal origin, no trailing slash |
| `COFFEE_OAUTH_CLAUDE_CLIENT_ID` | The client ID you entered in Claude |
| `COFFEE_OAUTH_CLAUDE_REDIRECT_URIS` | `https://claude.ai/api/mcp/auth_callback` |
| `COFFEE_OAUTH_CLAUDE_CLIENT_SECRET` | Leave unset for a public PKCE client |
| `COFFEE_OAUTH_PRIVATE_KEY_PATH` | RSA private key, readable, outside the web root |
| `COFFEE_OAUTH_PUBLIC_KEY_PATH` | Matching public key, outside the web root |
| `COFFEE_OAUTH_ENCRYPTION_KEY` | Independent random secret, at least 64 characters |

Separate variables with the `CHATGPT_` prefix configure ChatGPT. Multiple
callbacks for one provider can be separated by whitespace.

Generate the key pair with OpenSSL, store it in the environment's persistent
private files directory, and restrict its permissions. Keep secrets out of
Drupal config exports, git, frontend variables and chat messages.

Validate the configuration without printing secrets:

```sh
drush coffee:oauth-check
```

## Add the connector in Claude

1. Open **Customize → Connectors** in Claude on the web.
2. Choose **Add custom connector**.
3. Enter the MCP server URL:

   ```text
   https://YOUR-DRUPAL-ORIGIN/api/integrations/v1/mcp
   ```

4. For **Authentication**, choose **Sign in now** so each person uses their own
   account. Do not choose **No sign-in**: that shares one credential.
5. For **OAuth client**, choose **Use your own OAuth client** and enter the
   client ID from the environment settings. Leave the secret blank.
6. Add the connector, then select **Connect** and approve access in Drupal.

Claude can't change authentication settings after a connector is added. To
correct them, remove the connector and add it again.

Enable the connector per conversation from the **+** menu, then ask for your
coffees. Keep approval required for the create tool.

## What the server enforces

- Authorization code with mandatory **PKCE S256**; plain challenges are rejected.
- Exact client, callback, resource and scope validation on every request.
- Access tokens last 15 minutes and are bound to the MCP resource and client.
- Authorization codes are single use and expire in 5 minutes.
- Refresh tokens rotate. Reusing a consumed one revokes that grant family and
  returns `invalid_grant`, so the owner must reconnect.
- Grant families last at most 30 days and re-check the allowed, active account.
- Scopes are limited to `beans:read` and `beans:create`; refresh cannot widen them.
- Tokens work only on the MCP route, never as a browser session or for other APIs.
- Anonymous discovery exposes tool definitions only, with no journal or profile data.
- A retried save using the same `request_id` returns the original coffee, even
  after a token refresh.
- Stored records contain hashed identifiers and revocation state, never raw tokens.

## Verified locally

Against a real HTTP server with the production-shaped flow:

- 401 challenge with the `resource_metadata` pointer.
- Discovery documents, including Claude's path-suffixed probe location.
- Drupal login requirement, then CSRF-protected consent naming Claude and
  the claude.ai callback host.
- Code exchange, authorized tool list, stable profile identity and a created
  coffee attributed to Claude.
- Refresh rotation, retry safety, replay revocation and reconnect.
- ChatGPT's client rejected when using Claude's callback, and vice versa.
- Browser status, CSRF-protected disconnect and immediate loss of access.

Not yet done: connecting from a real Claude account, and Dev/Test/production
activation. Those remain the next steps.

## Disconnect and rollback

Disconnect an assistant from the coffee app profile, or revoke server-side.
Removing a connector inside Claude does not notify Drupal, so use the app's
Disconnect control to revoke access locally.

To stop all OAuth, set `COFFEE_OAUTH_ENABLED=0` and rebuild caches. Keep the
module and its tables installed so grants and journal attribution are preserved.
