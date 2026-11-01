# OAuth bootstrap progress

Source commit: a69743f28b637195fc9ae87dc61eb33bde1238c2
Pipeline: https://github.com/Shivam-Chandan/coffee-journal-app/actions/runs/41297589327

Production setup submitted:
- Issuer: https://eeschandan1.prod.acquia-sites.com
- Client ID: coffee-journal-chatgpt-prod
- Intended client registration: predefined/public, PKCE S256, token auth none.
- RSA 3072 signing pair and independent encryption secret generated on production in private storage, never printed or copied into this repository.
- Runtime file: /mnt/files/eeschandan1.prod/sites/default/files-private/coffee-oauth/runtime.json
- Discovery feature can be enabled separately from authorization/token issuance.

Authorization remains disabled pending the actual ChatGPT callback. No redirect URI was guessed, no existing bearer credential revoked, and no test entry created.

Local verification: OAuthFlowTest passed (11 tests, 209 assertions), including discovery-only bootstrap with authorization/token endpoints remaining unavailable. Settings PHP syntax check passed.

Remaining: complete deployment, verify public discovery, configure the existing plugin for provided OAuth, obtain its exact callback through the supported connection flow, register callback, validate production configuration and enable grants, then user login/consent and read checks. Coffee creation requires explicit confirmation.

Deployment completed successfully through Dev, Test and Prod. Both production discovery documents returned HTTP 200. MCP initialize returned HTTP 401 with a protected-resource OAuth discovery challenge; anonymous tool discovery remains unverified.

Original private plugin updated to 1.1.0, release pluginrel_6ae7159d982c8191857508bca34d3d93, with provided client coffee-journal-chatgpt-prod, token auth none and both beans scopes.

Next blocking input: exact callback URI from the user-visible ChatGPT connection flow. Automated browser access to chatgpt.com is blocked by organizational Zscaler policy. Authorization/token issuance remain disabled and no callback has been guessed.

## Update: multi-provider clients and Claude support

The server now registers each assistant as its own OAuth client, with its own
callbacks, grants, refresh families and revocation. Settings moved from single
`COFFEE_OAUTH_CLIENT_ID` / `COFFEE_OAUTH_REDIRECT_URI` values to per-provider
`COFFEE_OAUTH_CHATGPT_*` and `COFFEE_OAUTH_CLAUDE_*` variables, so the earlier
production variables must be re-entered under their new names before OAuth is
enabled again. The `COFFEE_OAUTH_DISCOVERY_ENABLED` flag was removed; discovery
follows the single `COFFEE_OAUTH_ENABLED` flag and the configured clients.

Claude is now the first target because its documented **"Use your own OAuth
client"** option matches this predefined-client implementation, and its hosted
callback is published rather than generated per connection. See
`claude-oauth-setup.md`. DCR and CIMD remain unimplemented and unadvertised;
Claude Code's loopback redirects are unsupported.

ChatGPT remains blocked on its exact callback URI. Its plugin bundle in
`coffee-journal-oauth/` still declares the ChatGPT provided-client settings and
is unchanged by the Claude work.

Verified locally against a real HTTP server for Claude: 401 discovery challenge,
both metadata locations, Drupal login requirement, CSRF-protected consent naming
the assistant and callback host, code exchange, authorized tools, stable profile
identity, a coffee attributed to Claude, refresh rotation, retry safety, replay
revocation, reconnect, cross-provider callback rejection, browser status and
CSRF-protected disconnect. Backend suites: 29 kernel tests / 523 assertions and
70 unit tests / 304 assertions. Frontend: 23 tests, zero Vercel functions.

Not done: connecting from a real Claude account, and enabling OAuth on any
deployed environment. No production credential was revoked and no journal entry
was left behind.
