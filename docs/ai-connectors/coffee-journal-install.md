# Coffee Journal private plugin

Created privately in the personal account (USER scope), version 1.0.0.
Plugin: https://chatgpt.com/plugins/plugins_6ae669f6c27081919016002e9934f120
Plugin ID: plugins_6ae669f6c27081919016002e9934f120
Release ID: pluginrel_6ae669f8bd7c8191a36fd40942b64220

## Files

```
coffee-journal.zip
coffee-journal/
  plugin.json
  mcp.json
  skills/coffee-journal/SKILL.md
```

The complete manifests and assistant instructions are in those files. No runtime handlers or static tool schemas are needed: the host discovers the existing remote MCP server. Its five expected tools are get_coffee_options, list_coffees, get_coffee, create_coffee and update_coffee. Discovery remains unverified until authentication.

## Install and connect

1. Open the plugin link in your personal ChatGPT account. Select Install plugin if shown. Keep it private; do not share it.
2. Follow the connection/setup prompt for Coffee Journal. The package declares API-key authentication with bearer scheme. Enter the Drupal-issued token only in the secure credential field. A token field normally takes the raw token; if the UI explicitly asks for the full Authorization header value, use `Bearer <COFFEE_JOURNAL_TOKEN>`. The placeholder is documentation, not an environment-variable expansion.
3. Review permissions. Keep approval required for creation. Select Coffee Journal in the conversation's plugin picker or mention it with @.
4. Ask “Show my coffees and the required fields for adding a coffee.” Verify discovery and the returned live results.

No OpenAI inference API key is needed. Do not put the token in chat, package files, URLs, frontend code, shell commands or logs. The host supplies Authorization server-side. For 401, replace/reconnect the credential through the same secure connection UI; the issued credential has a 90-day lifetime. Revoke compromised credentials in Drupal.

If this client's setup offers only OAuth/anonymous and no secure bearer credential entry, stop: this client cannot authenticate this endpoint through that flow. Do not choose anonymous or invent OAuth configuration. The package's supported bearer declaration does not prove a particular client exposes its credential UI. An OAuth adapter would require separate backend work.

## Acceptance checks

- Live options and list/search work; pagination preserves the query.
- Missing required facts prompt questions; optional unknown values are omitted.
- A proposed entry is summarized and explicitly confirmed before creation.
- Only after authorizing a real test save: verify the returned URL, configured ownership and journal labels AI · ChatGPT / Added via ChatGPT.
- Retry that same save with identical fields and request_id; verify the same UUID.
- Verify revoked/expired credentials prompt reconnection without revealing secrets.

Tested: JSON parsing, archive layout and successful private personal-account creation by Plugin Creator. Not tested: installation, credential storage UI, live authenticated discovery, read/create calls, deduplication or journal labels. No test entry was created. Tools are not yet exposed in this session.

## Platform references

- https://developers.openai.com/plugins/build/plugins
- https://learn.chatgpt.com/docs/plugins

Package contract was also checked against the installed Plugin Creator skill's plugin-packages.md and zip-requirements.md, including api_key / bearer under the remote server's extensions.com.openai.auth.
