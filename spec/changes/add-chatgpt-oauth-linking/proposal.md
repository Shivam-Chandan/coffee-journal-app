# ChatGPT OAuth account linking

Status: proposed; no OAuth implementation or production authentication changes
are included in this planning change.

## Why

Replace manual credential setup for the personal ChatGPT plugin with a supported
OAuth authorization-code + PKCE connection to the existing Drupal account.
Preserve journal ownership, permissions, retry protection and AI provenance.

OpenAI's current plugin/MCP authentication documentation requires an OAuth 2.1
compatible flow. The existing app-issued bearer token verifies our API, but is
not evidence that native ChatGPT account linking is supported or complete.

## What Changes

### User experience

1. The owner selects **Connect ChatGPT** on the React profile page.
2. The existing plugin page opens; the owner selects its Connect action unless
   ChatGPT documents a direct connection link for this plugin.
3. ChatGPT discovers authorization metadata and redirects to Drupal.
4. Drupal requires an authenticated, active, allowed pilot account and shows
   consent for **Read my coffees** and **Create coffees**.
5. Approval returns a short-lived code to ChatGPT's registered callback.
6. ChatGPT exchanges the code using PKCE and obtains scoped access/refresh tokens.
7. Authenticated MCP tools resolve the grant to the same Drupal account.
8. The profile page shows the server's authorization status and a Disconnect action.

Do not infer connection success from opening a URL, returning to the app, issuing
a code, or checking whether an old personal token exists. The browser session in
the React/Vercel app may not also sign the owner into the Drupal origin; allow a
normal Drupal login rather than forwarding session cookies or credentials.

### Scope

- Initial authorization remains restricted to the configured pilot Drupal UUID.
- ChatGPT uses the existing `/api/integrations/v1/mcp` endpoint and bean tools.
- Include an authenticated profile tool for stable account identification.
- Keep the static React/Vercel architecture: no added Vercel functions.
- Later multi-user onboarding and Claude/Gemini OAuth registration are separate
  releases; the grant model should allow multiple accounts/clients without baking
  the pilot restriction into storage.

### Phase 0: compatibility spike and contract decisions

Before changing production authentication:

1. Inspect the plugin's actual MCP management page and capture its non-secret
   OAuth fields, exact redirect URI, selected registration method and client
   authentication requirements. Confirm whether the uploaded plugin calls MCP
   directly or includes a separate tool runtime that must also be updated.
2. Audit a maintained Drupal authorization-server implementation, starting with
   `drupal/simple_oauth`, against installed Drupal/PHP and deployment constraints.
   Do not assume it implements MCP metadata, resource indicators, CIMD, issuer
   identification, refresh replay protection or all required PKCE behavior.
3. Produce a capability matrix: built-in support, bounded custom adapter work,
   and blockers. Prefer a predefined OAuth client for this one private plugin
   when its builder supports it. If registration requires CIMD or DCR, select
   supported maintained components rather than building a general OAuth server.
4. Prove authorization, code exchange and token validation on Dev with an MCP
   test client. If the selected implementation cannot meet the contract, stop
   at this gate and document an established-provider alternative and its Drupal
   identity mapping before adding dependencies.

Default recommendation: Drupal remains the authorization server and uses the
existing Drupal login. A hosted identity provider is a fallback decision, not
an additional account system to introduce without review.

### OAuth contract

| Concern | Proposed contract |
|---|---|
| Issuer | Canonical Drupal HTTPS origin, fixed per environment |
| Resource/audience | Canonical HTTPS MCP endpoint URL, fixed per environment |
| Grant | Authorization code with mandatory PKCE S256 |
| Scopes | `beans:read`, `beans:create`; existing Drupal permissions still apply |
| Access lifetime | Target 15 minutes, subject to verified provider support |
| Refresh lifetime | Target 30 days maximum, rotating with replay detection |
| Code lifetime | Target 5 minutes maximum; single-use and client/resource/redirect-bound |
| Client | Registered ChatGPT plugin; exact redirects, no wildcard callbacks |
| Identity | Immutable Drupal user UUID, never model-supplied email or user ID |

Record final values after the spike. Reject unsupported grant types and unknown
scopes. Require consent for any scope expansion; refresh cannot elevate scopes.
Handle `state` and preserve authorization-request context through Drupal login
using the maintained library; do not implement arbitrary return-URL redirects.

If using CIMD, accept only the reviewed OpenAI metadata identity for this pilot,
validate redirect metadata, and use bounded HTTPS fetching with SSRF protections.
If DCR is required, constrain registration, redirects and issuance; do not expose
an unrestricted client-registration service. Advertise only capabilities that
are implemented. In particular, advertise RFC 9207 issuer identification only
if every success and error authorization redirect includes the exact issuer.

### Discovery and MCP linking UI

Publish public, non-sensitive metadata:

- `/.well-known/oauth-protected-resource`, identifying the exact MCP resource,
  issuer and available scopes. Advertise this URL explicitly in 401 challenges
  so the path-mounted MCP endpoint is discoverable without URL guessing.
- `/.well-known/oauth-authorization-server` (or the selected provider's supported
  OIDC discovery endpoint), with real authorization/token endpoints, PKCE S256,
  supported client authentication and the chosen registration method.

The authorization/token endpoint paths come from the selected maintained module;
do not invent endpoint paths before confirming its implementation.

Carry and validate the `resource` parameter through authorization, code exchange
and refresh as required. Tokens must be bound to the expected resource/audience.
Validate signature or introspection result, issuer, audience, time validity,
scopes, grant revocation and account status on every authorized request.

Current `McpController` rejects all unauthenticated traffic before discovery.
Refactor for the documented linking contract:

- Permit only bounded protocol initialization, public tool definitions and
  metadata needed to start authorization without a user token.
- Never return journal entries, personal field values or profile identity from
  anonymous discovery. Apply anonymous IP request and session-creation limits.
- Describe OAuth scopes on each protected tool using the supported SDK wire
  format (`securitySchemes`; verify support in the installed PHP SDK).
- For unauthenticated protected tool calls, return the expected MCP error with
  `_meta["mcp/www_authenticate"]`, plus HTTP Bearer challenges where appropriate.
- Do not silently treat an invalid supplied token as authenticated or downgrade
  it into a data-bearing anonymous request.
- Keep discovery sessions separate from authenticated grant sessions; restart or
  securely rebind after linking. A session ID must never grant authorization.

### Reuse existing services through a trusted connection context

`ConnectorAuthentication` currently recognizes only `cj_...` tokens and builds
`_coffee_connection` from `PersonalConnections`. Add OAuth verification with clear
credential dispatch; failed OAuth verification must not fall through to another
identity. Check authentication-provider precedence and route allowlists so OAuth
does not accidentally authorize browser endpoints or unrelated entity APIs.

Map the verified grant to the existing context shape:

```text
account: resolved active Drupal account
id: persistent connection/grant identity, independent of token rotation
provider: chatgpt, derived from registered client configuration
scopes: validated granted scopes
```

Keep `ConnectorJournal`, `JournalInput`, `CreationProvenance` and existing explicit
owner constraints authoritative. Token claims/body parameters must not select a
different owner or source label. Pilot eligibility applies at consent, exchange,
refresh and resource access, including accounts blocked after linking.

Stable context IDs are essential: create receipts and MCP sessions currently use
connection IDs. Refresh must not change the identity used for deduplication.
For reauthorization of the same unrevoked connection, retain that identity. A
disconnect/reconnect creates a new grant; document that deduplication across
separate grants is not promised and never replay old pending writes automatically.

### Grant storage and secrets

Reuse the provider's token/grant entities where possible. Add only application
metadata needed for account UUID, registered client, provider, granted scopes,
status, authorized/last-used time and stable connection identity. Record schema
and lifecycle rules before implementing tables.

- No OAuth signing private keys, refresh tokens, client secrets or personal tokens
  in exported Drupal config, git, `VITE_*`, logs, URLs or browser storage.
- Store signing keys in Acquia-supported private runtime storage/secret settings.
  Use per-environment keys and test key rotation with any required overlap.
- Client configuration exports may contain public IDs/redirects; supply any
  required client secret through runtime secrets.
- Enforce immediate local revocation of access and refresh capability through
  grant-state checks, even when a JWT's expiry is in the future.

### Stable profile tool

Add `get_profile`, authenticated and read-only, with an empty argument object.
Return the current account's UUID as top-level `id`, with optional display name
and email. Include the declared output schema and `_meta["openai/profile"]: true`
in tool metadata, plus matching structured/text content. Preserve the ID across
refresh, reconnect and email/name changes. It is not an email-based lookup tool.

### Profile connection controls

Add session-authenticated, private/no-store contracts for status and disconnect
(proposed `/api/v1/me/connections/chatgpt`, GET and DELETE). Keep the existing
session/CSRF protection for DELETE; OAuth credentials must not authorize it.

UI states:

- **Not connected:** Connect ChatGPT; opens the known plugin URL.
- **Authorized:** Drupal records a live OAuth grant. Show scopes, authorization
  date and Open ChatGPT / Disconnect controls. Avoid claiming the plugin is still
  installed; Drupal cannot infer removal in ChatGPT without a supported signal.
- **Reconnect required:** grant expired/revoked or otherwise needs authorization.
- **Status unavailable:** request failed; do not imply signed-out/disconnected.

Refetch status on return/window focus and after disconnect. Do not use query-string
flags to mark a connection as successful. Keep the link/account controls limited
to the pilot while the pilot restriction is enabled.

### Migration and rollout

1. Build and test additive OAuth support on Dev with environment-specific issuer,
   resource, client and redirect configuration. Verify Test before production.
2. Retain existing personal credentials only through explicitly scoped legacy
   compatibility while migrating; do not convert them into OAuth grants or claim
   they are linked accounts.
3. Deploy metadata/authentication/backend first, then compatible UI. Publish the
   correct production OAuth configuration in the existing ChatGPT plugin.
4. Complete a real owner linking/read/confirmed-create/refresh/disconnect journey
   in ChatGPT. Check web and supported mobile flows; remove clearly marked test
   entries without altering existing journal data.
5. Revoke the old ChatGPT personal credential after OAuth works. Keep separately
   issued credentials for other providers independent until they migrate.
6. Disable new OAuth grants/resource access by feature flag if rollback is needed;
   preserve nodes, provenance and schema. Do not silently re-enable a revoked
   legacy credential. Roll back the plugin configuration explicitly if necessary.

## Impact

- Composer dependency review and maintained authorization-server module/config.
- Custom module auth, discovery, consent integration, grant context and MCP tools.
- React profile schemas/status/mutations and connection controls.
- Acquia environment-specific secrets, signing keys and OAuth registration.
- Kernel/HTTP/protocol/browser tests and plugin setup/runbooks.
- No OpenAI inference API key or inference service; ChatGPT handles the chat.

## Inputs needed before real-client integration

- Exact redirect URI from this plugin's MCP management page.
- Supported/selected registration mode: predefined client, CIMD or DCR.
- Supported token endpoint client authentication and any non-secret client ID.
- Confirmation of the plugin's actual tool transport/runtime and editable auth
  settings. Do not share secrets in the conversation.

These inputs do not block repository research and the Dev compatibility spike;
they do block claiming end-to-end ChatGPT linking is complete.

## References

- OpenAI: https://developers.openai.com/apps-sdk/build/auth
- MCP authorization: https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization
- Existing implementation: `spec/changes/add-personal-ai-journal-connectors/`
- Existing setup/runbook: `docs/ai-connectors/README.md`

Recheck provider documentation during implementation, especially redirect and
client-registration behavior. Do not treat example callback URLs as this
plugin's registered redirect.
