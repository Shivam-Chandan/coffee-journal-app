# Personal AI journal connectors and entry provenance

Status: approved; backend, UI, Actions contract and MCP adapter implemented and
released to production. The requested personal Drupal profile is enabled and a
ChatGPT credential issued. Actual private GPT/Claude configuration and chat-client
acceptance remain pending. See `execution.md`.

## Why

Allow the owner to read and create coffee entries from their personal ChatGPT,
then Claude, while keeping Drupal authoritative for persistence and access.
Clearly identify entries created through an AI connection in the coffee app.
Use consumer chat subscriptions where supported, without requiring an OpenAI
or Anthropic inference API key for those chat-app integrations.

## What Changes

### Scope and delivery order

1. Shared Drupal integration API, personal credentials, and persistent provenance.
2. Private ChatGPT GPT Actions integration and visible entry labels.
3. Remote MCP adapter for Claude, after confirming the account/client supports it.
4. Gemini or other clients when their actual tool/connector capabilities are
   verified. Consumer Gemini connectivity is not assumed or a launch dependency.

The first release supports coffee beans: field options, list/search, detail, and
create. Brew logging, edits/deletes through assistants, photo ingestion, and
multi-user onboarding are later extensions. Normal in-app editing still works.

### Existing implementation to reuse

- `coffee_journal_api` already exposes `/api/v1/beans` reads and session/CSRF
  protected writes through `HeadlessWriteController`.
- `HeadlessPresenter::BEAN_FIELDS` maps supported inputs, including title,
  roaster, estate, roast, quantity/unit, purchase date, notes, and ratings.
- Current writes perform entity validation and access checks and have a basic
  account-scoped idempotency cache. Extract shared business logic into services;
  do not call controllers from other controllers or duplicate field mappings.
- Current idempotency is a read/save/cache sequence without payload binding or
  atomic exclusion. Strengthen it for connector retries rather than assuming
  it already prevents concurrent duplicate writes.
- React has `BeanTile.tsx` and `BeanDetailPage.tsx`; the legacy Drupal theme
  remains supported and needs equivalent provenance presentation.

### Architecture

```text
Private ChatGPT GPT → Actions/OpenAPI ─────────────┐
                                                 ├→ Shared journal services → Drupal nodes
Claude connector → remote MCP adapter ────────────┘
React + Drupal UI → existing session routes ──────┘
```

Host the integration on Drupal/Acquia over HTTPS. Keep the React deployment
static with zero additional Vercel functions. Implement an MCP transport using
a maintained compatible library where practical; select it after an Acquia/PHP
compatibility spike. The adapter translates tools to the same services and does
not make inference calls.

### Personal authentication

- Enable connections only for one explicitly configured Drupal account UUID;
  resolve it per environment, without hardcoding a numeric user ID.
- For GPT Actions, issue a high-entropy, revocable bearer credential bound to
  that account, a connection ID, `chatgpt` source, expiry, and `beans:read` /
  `beans:create` scopes. Store only its hash server-side; show plaintext once.
- Provision/revoke via an admin-only operation initially. Secrets belong in
  GPT Action authentication settings, never instructions, config exports,
  frontend variables, or logs. This is an app-issued credential, not an OpenAI key.
- Expose dedicated `/api/integrations/v1/...` routes with explicit connector
  authentication. Reject session-cookie-only requests on these routes; retain
  existing browser session/CSRF behavior on `/api/v1/...`.
- Set the Drupal authenticated account from verified credentials and enforce
  both explicit owner scoping and normal entity/field permissions. Credentials
  must not grant access to other entries merely because the owner is an admin.
- Check expiry, revocation, enabled pilot account, account blocked status, scope,
  and rate/body limits on every request. Return private, non-cacheable responses.
- Claude remote connector authentication is a Phase 3 compatibility decision:
  do not assume its UI accepts arbitrary bearer headers. If required, use a
  maintained OAuth implementation with compatible discovery/registration and
  PKCE, restricting authorization to the same pilot account. Bind the authorized
  client/grant to its own connection and `claude` source. Do not ship an anonymous
  MCP endpoint or hand-roll OAuth to work around incompatibility.

### Proposed API and tools

| HTTP operation | MCP tool | Purpose |
|---|---|---|
| `GET /api/integrations/v1/bean-options` | `get_coffee_options` | Required fields, types, units and allowed values |
| `GET /api/integrations/v1/beans` | `list_coffees` | Owner-only paginated list/search |
| `GET /api/integrations/v1/beans/{uuid}` | `get_coffee` | Owner-only details |
| `POST /api/integrations/v1/beans` | `create_coffee` | Validate and create for the connected account |

Publish a bounded OpenAPI contract for GPT Actions and matching MCP schemas.
Only include supported fields; do not invent structured process/origin fields
that do not currently exist. Keep genuinely unknown optional values empty and
ask for missing required information. Return structured validation errors and
the saved UUID, app URL, and provenance.

Use a required `request_id` in the create body/tool arguments, since provider
support for model-generated custom headers should not be assumed. Map it to
shared idempotency handling. Scope deduplication to account + connection +
operation + request ID, bind it to the normalized payload, and make concurrent
creation atomic. Retain successful results for at least 24 hours, reject key
reuse with different content, and return the same node for retries. The client
must preserve the request ID after an uncertain timeout; a new ID is a new
operation, not guaranteed deduplication.

### AI identifier: stored provenance and visible badge

Store server-controlled creation metadata on each new coffee:

| Property | Meaning |
|---|---|
| `creation_channel` | `app`, `ai_connector`, or `unknown` |
| `creation_provider` | `chatgpt`, `claude`, or a future registered provider; null otherwise |
| `creation_connection_id` | Internal connection reference; never expose credentials |
| `created_at` | Existing Drupal creation timestamp |

Use Drupal fields created through configuration and exported with `drush cex`.
Keep internal connection metadata out of public presentation and ordinary edit
forms. Set provenance in trusted application code at creation, not from request
payloads; deny attempts to set it through generic entity-write paths as well.
Persist it when connections are revoked or deleted. Provider identifies the
configured connection, not cryptographic proof of which model generated text.

Presentation:

- Coffee cards: a small text badge **AI · ChatGPT** or **AI · Claude**.
- Coffee detail: **Added via ChatGPT** / **Added via Claude**, with creation date.
- Unknown future provider fallback: **Added via AI**.
- Existing records: `unknown`, no AI badge; never infer historical origin from
  notes, existing Gemini scraping, or timestamps.
- New entries saved in the app: `app`, no AI-created badge. AI-assisted autofill
  in the existing app is distinct from creation through a chat connector.
- Human edits preserve the original badge. This describes creation origin,
  not who authored every current field.
- Add read-only `provenance` to bean card/detail JSON and frontend types. Render
  text with an accessible SVG icon in light/dark mode; do not rely on color alone.
- Match the label in legacy Drupal via preprocess/render arrays and Twig.

### ChatGPT setup and user journey

Create a private GPT in ChatGPT's web editor, import OpenAPI, and install the
personal credential in Action authentication settings. Mark creation with
`x-openai-isConsequential: true`; instruct the GPT to summarize proposed fields,
ask for missing required data, and return the actual saved link. Verify the
confirmation UX in the supported ChatGPT web/mobile clients. Do not claim every
ordinary ChatGPT conversation automatically gets access.

Example: “Add a 250g bag of Blue Tokai coffee, light roast, notes of jasmine.”
The GPT asks for any missing required values, presents the proposed entry,
creates it after confirmation, then returns the link. The journal immediately
shows **AI · ChatGPT**, including after refresh or later human edits.

### Rollout and acceptance

Deploy additive schema/services first, then UI and the private ChatGPT setup.
Verify on a stable HTTPS dev/test endpoint before provisioning production
credentials. Confirm the deployment path runs any required database updates
before config import; current documented hooks only promise import/cache clear.
Run `drush cr` after relevant Drupal changes. Release Claude separately after
testing MCP transport and auth with the actual client.

Acceptance includes successful chat-to-app creation, field error recovery,
matching badges in both UIs, unchanged manual creates, historical unknowns,
human edits retaining provenance, two-account isolation, credential revocation,
source-spoof rejection, and concurrent/retried saves creating one node.
Disable connector routes/revoke credentials to roll back access while retaining
created entries and provenance.

## Impact

- Custom module services, authentication, routes, schema/update logic and tests.
- Exported coffee bean field configuration and API contracts.
- React API types, bean tiles/details and an accessible shared provenance badge.
- Custom Drupal theme preprocess/Twig for existing coffee displays.
- Setup/runbooks for personal credentials, ChatGPT Actions and Claude MCP.
- No provider inference billing for the consumer-app path; chat subscriptions,
  client availability/limits and normal application hosting still apply.

## Decisions to confirm during implementation

- Target Drupal account UUID and stable external Drupal/frontend domains.
- Actual ChatGPT Actions availability in the owner's account and mobile client.
- Claude account/client support, remote transport and required auth mechanism.
- Gemini's specific client and supported integration surface before scheduling it.

Recommended defaults: ChatGPT first; read/create coffee beans only; source badges
on cards and details; personal-only credentials; Claude as the second milestone.
