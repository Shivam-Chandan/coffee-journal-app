# Help configure OAuth for my Coffee Journal ChatGPT plugin

I can see my plugin's MCP server in ChatGPT, but I cannot find its detailed
authentication settings. Help me determine what this ChatGPT interface supports
and where I can inspect or configure OAuth.

Use the current official OpenAI documentation and the actual interface available
to me. If you cannot inspect my settings directly, give precise navigation steps
or ask for a screenshot. Do not claim to have inspected settings you cannot access.

## My existing integration

- Plugin name: **Coffee Journal**
- Existing plugin URL:
  https://chatgpt.com/plugins/plugins_6ae669f6c27081919016002e9934f120
- Existing production MCP endpoint:
  https://eeschandan1.prod.acquia-sites.com/api/integrations/v1/mcp
- Coffee app:
  https://coffee-journal-vert.vercel.app
- I use my personal ChatGPT Plus account.
- Drupal owns my account, permissions and coffee records.
- The plugin should read and create coffees only for my connected Drupal profile.

The current production endpoint supports an app-issued bearer credential. A new
OAuth implementation has been built and tested locally, but **OAuth is not yet
deployed or enabled on the production endpoint**. Do not expect production OAuth
discovery to work yet, and do not interpret its absence as proof that the new
implementation is broken.

## What has been implemented locally

- Authorization-code flow with mandatory **PKCE S256**.
- Drupal login and explicit consent.
- Scopes: `beans:read` and `beans:create`.
- Resource-bound access tokens, rotating refresh tokens and revocation.
- MCP protected-resource and authorization-server discovery metadata.
- Per-tool OAuth declarations and authentication challenges.
- Authenticated `get_profile` with a stable account ID.
- Connection-status and Disconnect controls in the coffee app.
- One **predefined/manually configured OAuth client**.

The current implementation does **not** implement dynamic client registration
(DCR), Client ID Metadata Documents (CIMD), `private_key_jwt` client authentication,
or OpenID Connect. If ChatGPT requires one of those for this connection, identify
it clearly so the backend developer can add the necessary support.

## Questions to investigate

### 1. Identify the actual integration type

- Is this plugin connected directly to a remote MCP server?
- Was it installed from an uploaded/local plugin package, and does that package
  reference a separate MCP connection?
- Is the visible server owned/editable by me, or an installed connection whose
  server settings are managed elsewhere?
- Where do I open the **server management/authentication configuration**, rather
  than just its tool list or plugin overview?

Explain the relevant distinction using the current UI terminology. Do not assume
that the presence of a plugin page proves that its MCP authentication is configured.

### 2. Find the OAuth settings

- Can I edit authentication on this existing MCP connection?
- Where are the controls for selecting OAuth and entering client information?
- Is there a separate management page, advanced section or developer setting?
- Does my account/workspace allow this configuration?
- If editing is unavailable, must I add a new custom MCP server connection or
  recreate the plugin? Confirm before instructing me to remove anything.

If the labels differ from documentation, ask me for a screenshot of the visible
page. Do not invent a gear menu or settings screen.

### 3. Confirm client registration support

Which registration method does this ChatGPT connection support or require?

- **Predefined/manual client:** I enter a client ID supplied by the Drupal server.
- **CIMD:** ChatGPT supplies a client metadata document URL as its client identity.
- **DCR:** ChatGPT registers a client using a registration endpoint.

Please determine:

- Can I choose a predefined/manual client for this private connection?
- If multiple modes are supported, where do I select one?
- If CIMD/DCR is required, what exact metadata and server behavior are needed?
- Does the current plugin package/manifest control the choice? If so, identify
  the documented field and where it belongs; do not invent manifest properties.

### 4. Obtain the exact OAuth callback/redirect URI

My Drupal server needs an exact redirect URI allowlist.

- Where does ChatGPT display the callback for **this specific MCP connection**?
- Is it generated before creating the connection, during discovery, or afterward?
- Does it differ between a development connection and the production plugin?
- If a new connection must be created, does it receive a different callback?
- Does the callback depend on authorization-server issuer-identification support?

Do not give me an example callback as if it were my registered callback. In
particular, do not assume either a stable callback or a callback-ID-specific URL
without confirming the mode for this connection.

If the URI cannot be obtained until the server is deployed, explain the exact
bootstrap sequence that avoids needing the final callback before it can be
generated. The developer can expose a Dev discovery endpoint first, with token
issuance still unavailable until the real client/callback is configured.

### 5. Determine token endpoint client authentication

The backend currently supports:

- `none`: a public client using authorization-code + PKCE, without a client secret.
- `client_secret_post`: a predefined confidential client's secret in the token
  request body.
- `client_secret_basic`: a predefined confidential client's HTTP Basic credentials.

Check which of these ChatGPT supports for the chosen registration method and how
it is configured. Also determine whether it requires `private_key_jwt`, which
would need additional backend implementation.

Do not ask me to paste a client secret, bearer token, refresh token, password or
signing key into the conversation. Describe where secrets must be entered in
secure settings. A client ID and callback URI are non-secret configuration.

### 6. Confirm OAuth discovery and MCP requirements

Check the current required behavior for:

- Protected-resource metadata and the `WWW-Authenticate` discovery challenge.
- Authorization-server metadata and advertised PKCE `S256` support.
- The `resource` parameter on authorization, token exchange and refresh.
- The exact expected resource/audience value for a path-mounted MCP endpoint.
- Per-tool `securitySchemes` and `_meta["mcp/www_authenticate"]` errors.
- Public initialization/tool-definition discovery before account linking.
- Restarting an MCP session after authentication if the anonymous session is
  no longer valid in the authenticated connection.
- The authenticated `get_profile` output schema and
  `_meta["openai/profile"]: true` declaration.

The intended production resource is:

```text
https://eeschandan1.prod.acquia-sites.com/api/integrations/v1/mcp
```

Dev must use its own issuer/resource URLs and keys. Do not reuse production
credentials for development testing.

### 7. Explain updating and testing the existing plugin

- After the OAuth backend is deployed, should I Refresh the MCP connection,
  reconnect, reinstall the plugin, or publish a new plugin version?
- Will this preserve the existing plugin URL?
- Which action starts the owner login/consent flow?
- Can I verify the connection in a private draft/development setup before
  changing the working production plugin?
- What supported web and mobile clients can use the resulting connection?
- Can an app's **Connect ChatGPT** button start linking through a documented
  URL, or can it only open the plugin page where I select Connect?

Do not invent a native-app URL scheme or an authorization deep link. If only
the plugin page URL is supported, say so.

## Expected account-linking experience

1. I open the Coffee Journal plugin and choose Connect.
2. ChatGPT discovers the OAuth server and redirects me to Drupal.
3. I sign in to Drupal if necessary and approve read/create access.
4. ChatGPT exchanges a one-time authorization code with PKCE.
5. The plugin can read my journal and create a coffee after my confirmation.
6. New entries display **Added via ChatGPT** in the coffee app.
7. Disconnecting in the coffee app revokes access and refresh capability.

This does not require an OpenAI inference API key. Do not suggest pasting the
existing personal bearer token into chat as an OAuth substitute.

## Return your findings in this format

### A. What I should click

Give step-by-step navigation from my existing plugin/MCP server page. Distinguish
instructions verified in current official documentation from UI details you
still need me to confirm.

### B. Configuration findings

| Item | Confirmed value or requirement | Where it was found | Status |
|---|---|---|---|
| Connection type/ownership | | | |
| OAuth settings location | | | |
| Predefined/manual client supported? | | | |
| Required registration mode | | | |
| Exact redirect URI for this connection | | | |
| Client ID, if available | | | |
| Token endpoint client authentication | | | |
| Callback-generation/bootstrap sequence | | | |
| Refresh/reinstall/version-update requirements | | | |
| Supported connect link/deep link | | | |

Use **Confirmed**, **Documented but not verified in my account**, or **Unknown**
for status. Do not fill unknown fields with guessed values.

### C. Changes needed from the backend developer

List concrete incompatibilities, if any. For example: predefined clients are
unavailable and CIMD is required, or the client requires a different callback
mode. Separate these from OAuth endpoints that are simply not deployed yet.

### D. What I should send back

Provide a short non-secret summary I can copy back to the coding agent. Include
the exact redirect URI when available, registration method, token authentication
method, and any required backend changes. Identify missing information explicitly.

## Official documentation starting points

- https://developers.openai.com/apps-sdk/build/auth
- https://developers.openai.com/apps-sdk/deploy/connect-chatgpt
- https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization

Recheck the current documentation rather than relying on retired GPT editor or
legacy plugin instructions. Do not change or delete my existing plugin or
connection without asking me first.
